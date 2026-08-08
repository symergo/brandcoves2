import { Head, usePage } from '@inertiajs/react'
import { useCallback, useEffect, useRef, useState } from 'react'
import type { Cents, SharedProps } from '../types'
import { formatPrice } from '../types'
import { useTranslations } from '../useTranslations'

interface Hit {
    status: 'found' | 'not_found' | 'invalid'
    gtin?: string
    title?: string
    image?: string | null
    price?: Cents | null
    merchantCount?: number
    inStock?: boolean
    url?: string
    searchUrl?: string
    message?: string
}

interface Detector {
    detect: (source: CanvasImageSource) => Promise<{ rawValue: string }[]>
}

declare global {
    interface Window {
        BarcodeDetector?: new (options?: { formats?: string[] }) => Detector
    }
}

const FORMATS = ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'itf', 'code_128']

/**
 * A detector that works in every browser, not just the lucky ones.
 *
 * The native `BarcodeDetector` is not a general capability: Chrome ships it on
 * Android, ChromeOS and macOS, and **not on Windows or Linux desktop**, and
 * Safari and Firefox do not ship it at all. Feature-detecting it and showing
 * "your browser cannot scan" is technically correct and useless — most desktops
 * land there, and it looks like the feature is broken.
 *
 * So: native where it exists, and a WebAssembly decoder (ZXing) everywhere
 * else. The polyfill is imported dynamically, inside the click handler, so the
 * decoder — a megabyte of wasm, 450 KB over the wire — is fetched only by
 * someone who actually pressed "scan", and never by the pages that share this
 * bundle. That is also why the button shows a preparing state: on a phone the
 * fetch is noticeable, and a button that appears to do nothing gets pressed
 * again.
 */
async function makeDetector(): Promise<Detector> {
    if (typeof window !== 'undefined' && window.BarcodeDetector) {
        return new window.BarcodeDetector({ formats: FORMATS })
    }

    const [{ BarcodeDetector: Polyfill }, { prepareZXingModule }] = await Promise.all([
        import('barcode-detector/pure'),
        import('zxing-wasm/reader'),
    ])

    /*
     * Serve the wasm ourselves.
     *
     * By default zxing-wasm fetches its binary from a public CDN, which makes a
     * core feature depend on a third party at runtime — and fail silently when
     * that third party is blocked, slow, or ships a different build than the JS
     * expects. Vite fingerprints and emits the file from node_modules, so it is
     * served from our own origin and versioned with the bundle.
     */
    const wasmUrl = (await import('zxing-wasm/reader/zxing_reader.wasm?url')).default

    prepareZXingModule({ overrides: { locateFile: () => wasmUrl } })

    return new Polyfill({ formats: FORMATS }) as Detector
}

export default function Scan() {
    const { market } = usePage<SharedProps>().props
    const { t, n } = useTranslations()

    const videoRef = useRef<HTMLVideoElement | null>(null)
    const streamRef = useRef<MediaStream | null>(null)
    const lastCodeRef = useRef<string | null>(null)

    const detectorRef = useRef<Detector | null>(null)

    const [scanning, setScanning] = useState(false)
    const [loading, setLoading] = useState(false)
    // Which error, not its wording. `t` is a new closure on every render, so
    // holding translated text here would drag `t` into the deps of the camera
    // effect below and restart the decoder on every keystroke in the manual
    // field. Translated at render instead.
    const [errorKey, setErrorKey] = useState<string | null>(null)
    const [hit, setHit] = useState<Hit | null>(null)
    const [manual, setManual] = useState('')

    const lookup = useCallback(
        async (code: string) => {
            // The camera fires the same barcode many times a second. Without
            // this the first successful read becomes a burst of identical
            // requests.
            if (lastCodeRef.current === code) return
            lastCodeRef.current = code

            const response = await fetch(`/${market.key}/scan/${encodeURIComponent(code)}`, {
                headers: { Accept: 'application/json' },
            })

            setHit(await response.json())
        },
        [market.key],
    )

    const stop = useCallback(() => {
        streamRef.current?.getTracks().forEach((track) => track.stop())
        streamRef.current = null
        setScanning(false)
    }, [])

    const start = useCallback(async () => {
        setErrorKey(null)
        setHit(null)
        lastCodeRef.current = null
        setLoading(true)

        try {
            // Built once and kept: the wasm decoder is expensive to construct
            // and there is no reason to pay for it again on a second scan.
            detectorRef.current ??= await makeDetector()
        } catch {
            setErrorKey('scan.unsupported')
            setLoading(false)

            return
        }

        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                // The rear camera, and a resolution high enough to resolve the
                // bars. `ideal` rather than `exact` so a laptop with only a
                // front camera still works instead of throwing.
                video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 } },
            })

            streamRef.current = stream

            // The stream is attached to the <video> by the effect below, not
            // here. The element is only rendered while `scanning` is true, so
            // at this point `videoRef.current` is still null — see the comment
            // on that effect.
            setScanning(true)
        } catch {
            // Denied, or no camera. Either way the manual field below still
            // works, so this is a message rather than a dead end.
            setErrorKey('scan.no_camera')
        } finally {
            setLoading(false)
        }
    }, [])

    // Release the camera on unmount. A page that keeps the light on after the
    // visitor has navigated away is the fastest way to lose their trust.
    useEffect(() => stop, [stop])

    /*
     * Show the camera, then read from it.
     *
     * The stream is attached here rather than in `start()` because the <video>
     * is rendered only while `scanning` is true: when `start()` has the stream
     * in hand the element is not mounted yet and the ref is still null, so the
     * assignment there landed on nothing. The camera came on, the preview
     * stayed black, and every frame threw `InvalidStateError` into the silent
     * catch below — the scanner looked like it was running and could never
     * decode anything, in every browser. An effect runs after the commit that
     * mounts the element, which is the only point at which the ref exists.
     */
    useEffect(() => {
        const video = videoRef.current
        const stream = streamRef.current
        const detector = detectorRef.current

        if (!scanning || video === null || stream === null || detector === null) return

        let cancelled = false

        video.srcObject = stream

        // Muted and playsInline, so autoplay policies allow this. A rejection
        // means no preview and therefore no frames to read, so say so rather
        // than spinning on a video that will never produce one.
        video.play().catch(() => {
            if (!cancelled) setErrorKey('scan.unsupported')
        })

        const tick = async () => {
            if (cancelled) return

            // Only ask about a frame that exists. The decoder throws
            // `InvalidStateError` for a video below HAVE_CURRENT_DATA, which
            // the catch below cannot tell apart from an unreadable barcode —
            // that is what hid the bug above. Frames start arriving a moment
            // after play(), so the early ticks legitimately land here.
            if (video.readyState >= video.HAVE_CURRENT_DATA) {
                try {
                    const codes = await detector.detect(video)

                    if (codes.length > 0) {
                        await lookup(codes[0].rawValue)
                    }
                } catch {
                    // A frame that cannot be decoded is the normal case, not an
                    // error. Swallow and try the next one.
                }
            }

            if (!cancelled) setTimeout(tick, 300)
        }

        void tick()

        return () => {
            cancelled = true
            video.srcObject = null
        }
    }, [scanning, lookup])

    return (
        <>
            <Head title={t('scan.title')} />

            <header className="max-w-2xl">
                <h1 className="text-2xl font-semibold sm:text-3xl">{t('scan.title')}</h1>
                <p className="mt-2 text-ink-soft">{t('scan.subtitle')}</p>
            </header>

            <div className="mt-6 max-w-md">
                {!scanning ? (
                    <button
                        type="button"
                        onClick={start}
                        disabled={loading}
                        className="w-full rounded bg-accent px-5 py-3 font-medium text-white disabled:opacity-60"
                    >
                        {loading ? t('scan.preparing') : t('scan.start')}
                    </button>
                ) : (
                    <div className="space-y-3">
                        <video
                            ref={videoRef}
                            className="w-full rounded-lg border border-line bg-black"
                            muted
                            playsInline
                        />
                        <button
                            type="button"
                            onClick={stop}
                            className="w-full rounded border border-line px-5 py-2 text-sm"
                        >
                            {t('scan.stop')}
                        </button>
                    </div>
                )}

                {errorKey && <p className="mt-3 text-sm text-ink-soft">{t(errorKey)}</p>}

                {/*
                  Always available, not a fallback shown only on failure.
                  Safari has no BarcodeDetector, shop lighting defeats a camera
                  regularly, and some people would simply rather type.
                */}
                <form
                    className="mt-6 flex gap-2"
                    onSubmit={(e) => {
                        e.preventDefault()
                        lastCodeRef.current = null
                        if (manual.trim() !== '') void lookup(manual.trim())
                    }}
                >
                    <input
                        type="text"
                        inputMode="numeric"
                        className="min-w-0 flex-1 rounded border border-line px-3 py-2"
                        placeholder={t('scan.manual_placeholder')}
                        value={manual}
                        onChange={(e) => setManual(e.target.value)}
                        aria-label={t('scan.manual_placeholder')}
                    />
                    <button type="submit" className="rounded border border-line px-4 text-sm">
                        {t('scan.look_up')}
                    </button>
                </form>
            </div>

            {hit && (
                <section className="mt-8 max-w-md rounded-lg border border-line bg-card p-5">
                    {hit.status === 'found' ? (
                        <a href={hit.url} className="flex gap-4">
                            {hit.image && (
                                <img
                                    src={hit.image}
                                    alt=""
                                    className="h-24 w-24 shrink-0 object-contain"
                                />
                            )}
                            <div className="min-w-0">
                                <p className="font-medium">{hit.title}</p>
                                <p className="mt-1 text-lg font-semibold">
                                    {hit.price == null ? '—' : formatPrice(hit.price, market)}
                                </p>
                                {(hit.merchantCount ?? 0) > 1 && (
                                    <p className="text-sm text-ink-soft">
                                        {t('scan.shops', { count: n(hit.merchantCount ?? 0) })}
                                    </p>
                                )}
                            </div>
                        </a>
                    ) : (
                        <div className="text-sm">
                            <p>{hit.message}</p>
                            {hit.searchUrl && (
                                <a href={hit.searchUrl} className="mt-2 inline-block underline">
                                    {t('scan.search_instead')}
                                </a>
                            )}
                        </div>
                    )}
                </section>
            )}
        </>
    )
}

/**
 * Our own resized WebP copies of a merchant's picture (docs/features/image-proxy.md).
 *
 * The server hands out a signed `imageToken` beside `image` for each picture
 * the proxy may serve, and null for one it may not (proxy switched off, a host
 * not on its list, Amazon). Only the server can sign, so the browser never
 * builds an address for a picture the server did not vouch for; it only picks
 * the width.
 *
 * The widths must match `giftcoves.image_proxy.widths`: any other is a 404.
 */
export const IMAGE_WIDTHS = [160, 320, 480, 640, 960] as const

export type ImageWidth = (typeof IMAGE_WIDTHS)[number]

/**
 * Bumped with `ProxiedImages::VERSION` on the server. A copy is served with a
 * year's `immutable` cache, so a browser or Cloudflare that holds an old copy
 * never asks again; a new query string is a new address to them, while the
 * route ignores it, so older addresses in cached pages still work.
 */
const IMAGE_VERSION = 2

/** The proxied address at one width. */
export function imageUrl(token: string, width: ImageWidth): string {
    return `/img/${width}/${token}?v=${IMAGE_VERSION}`
}

/** A `srcset` over the given widths, for the browser to pick from with `sizes`. */
export function imageSrcSet(token: string, widths: readonly ImageWidth[] = IMAGE_WIDTHS): string {
    return widths.map((w) => `${imageUrl(token, w)} ${w}w`).join(', ')
}

/**
 * The shop's own picture per width (`ShopImageSizes` on the server), for the
 * image servers that take a size in the URL. Null for any other.
 */
export type ShopSizes = Partial<Record<ImageWidth, string>> | null | undefined

/**
 * The attributes for one picture, best first:
 *
 * 1. the proxied copies, when there is a token (the proxy is switched off since
 *    2026-10-25, so in practice never; kept so switching it on needs no code);
 * 2. the shop's own sizes, when its image server takes one;
 * 3. the shop's URL as it is.
 *
 * `failed` is the caller's state, set from `onError`: when a resized address
 * fails (a proxy that is unreachable, a shop rule that stopped matching), the
 * picture falls back to the plain URL, and only a second failure is broken.
 */
export function pictureAttributes(
    src: string,
    token: string | null | undefined,
    failed: boolean,
    fallbackWidth: ImageWidth,
    sizes: string,
    widths: readonly ImageWidth[] = IMAGE_WIDTHS,
    shopSizes: ShopSizes = null,
): { src: string; srcSet?: string; sizes?: string } {
    if (failed) {
        return { src }
    }

    if (token) {
        return { src: imageUrl(token, fallbackWidth), srcSet: imageSrcSet(token, widths), sizes }
    }

    if (shopSizes) {
        // eBay answers fixed sizes only, so two widths can name the same
        // address; the browser needs each once.
        const seen = new Set<string>()
        const entries = widths.flatMap((w) => {
            const url = shopSizes[w]
            if (!url || seen.has(url)) {
                return []
            }
            seen.add(url)
            return [`${url} ${w}w`]
        })

        if (entries.length > 0) {
            return { src: shopSizes[fallbackWidth] ?? src, srcSet: entries.join(', '), sizes }
        }
    }

    return { src }
}

/** Whether a picture has a resized address that can fail before the plain one. */
export function hasResized(token: string | null | undefined, shopSizes: ShopSizes): boolean {
    return !!token || !!shopSizes
}

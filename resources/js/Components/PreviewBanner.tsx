import { useTranslations } from '../useTranslations'

/**
 * "You are looking at something nobody else can see."
 *
 * Unmissable on purpose. A draft rendered by the real controller looks exactly
 * like the live page — which is the point of previewing it that way — and the
 * failure mode of that fidelity is an editor who believes a piece is published
 * because they have been reading it all afternoon.
 */
export default function PreviewBanner() {
    const { t } = useTranslations()

    return (
        <div
            role="status"
            className="mb-6 rounded-card border border-amber/50 bg-amber/10 p-4 text-sm"
        >
            <strong className="font-medium">{t('preview.badge')}</strong>{' '}
            <span className="text-ink-soft">{t('preview.note')}</span>
        </div>
    )
}

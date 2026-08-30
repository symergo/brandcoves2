import { type BlockPayload, Paragraph } from './Parts'

/**
 * A region rendered as a single column of prose.
 *
 * The `flow` layout: above the grid, and inside an empty state. No column grid,
 * because those places are narrow and short by design — a region that wants
 * columns is `below_grid`, and that one is assembled into sections server-side.
 *
 * Renders nothing at all when the region is empty, which is the ordinary state
 * of `above_grid` until somebody writes something. There is no fallback beneath
 * it and that is deliberate: fixed system text is what this replaced.
 */
export default function PageBlocks({
    blocks,
    className,
}: {
    blocks: BlockPayload[] | null
    className?: string
}) {
    if (!blocks || blocks.length === 0) return null

    return (
        <div className={className}>
            {blocks.map((block, i) =>
                block.kind === 'heading' ? (
                    <h2 key={i} className="mb-1 font-semibold">
                        {block.parts.map((p) => (p.t === 'text' ? p.v : '')).join('')}
                    </h2>
                ) : (
                    <Paragraph
                        key={i}
                        parts={block.parts}
                        className="text-sm leading-relaxed text-ink-soft [&+p]:mt-2"
                    />
                ),
            )}
        </div>
    )
}

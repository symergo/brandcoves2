import ToolIcon from './ToolIcon'

/** The check on a chosen choice (choiceCard.ts): so chosen is not told by colour alone. */
export default function ChoiceCheck({ on, className = '' }: { on: boolean; className?: string }) {
    return on ? <ToolIcon name="check" className={`h-4 w-4 shrink-0 text-accent ${className}`} /> : null
}

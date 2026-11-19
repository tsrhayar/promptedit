import { Ban, CircleCheck, CircleX, Clock, LoaderCircle, Send, type LucideIcon } from 'lucide-vue-next';

// One place that says how each generation status looks and what it means for the user.
export type GenerationStatus = 'pending' | 'queued' | 'processing' | 'succeeded' | 'failed' | 'rejected';

export type StatusTone = 'neutral' | 'info' | 'success' | 'danger';

export interface StatusMeta {
    label: string;
    /** Plain-language explanation shown under the image while it is not done. */
    hint: string;
    icon: LucideIcon;
    tone: StatusTone;
    /** Spin the icon: something is actively happening. */
    spin?: boolean;
}

export const STATUS: Record<GenerationStatus, StatusMeta> = {
    pending: { label: 'Sending', hint: 'Sending your request to the image provider…', icon: Send, tone: 'neutral' },
    queued: { label: 'Queued', hint: 'Waiting for a free slot. This usually takes a few seconds.', icon: Clock, tone: 'neutral' },
    processing: { label: 'Generating', hint: 'Your image is being generated…', icon: LoaderCircle, tone: 'info', spin: true },
    succeeded: { label: 'Done', hint: '', icon: CircleCheck, tone: 'success' },
    failed: { label: 'Failed', hint: '', icon: CircleX, tone: 'danger' },
    rejected: { label: 'Not sent', hint: '', icon: Ban, tone: 'danger' },
};

export const ACTIVE_STATUSES: GenerationStatus[] = ['pending', 'queued', 'processing'];
export const FAILED_STATUSES: GenerationStatus[] = ['failed', 'rejected'];

/** Short human title for an error code; the server's message gives the details. */
export function errorTitle(code: string | null): string {
    switch (code) {
        case 'content_policy_violation':
            return 'Blocked by the content policy';
        case 'overloaded':
            return 'The image provider is busy';
        case 'rate_limit_exceeded':
            return 'Too many requests';
        case 'daily_limit_exceeded':
            return 'Daily limit reached';
        case 'validation_failed':
        case 'invalid_api_key':
        case 'idempotency_key_reused':
            return 'Could not send the request';
        default:
            return 'The generation failed';
    }
}

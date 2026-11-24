import type { Quality, Size } from '@/types';

export type GenerateChoices = { size: Size; quality: Quality };

/**
 * The size and quality of the user's last submit, kept for the rest of the
 * visit, so the form starts with them again after the redirect to History
 * and a repeat Generation only needs a new prompt. Only a successful submit
 * writes it, and that never happens during server-side rendering.
 */
let lastSubmitted: GenerateChoices | null = null;

export function useGenerateChoices(defaults: GenerateChoices): {
    initial: GenerateChoices;
    remember: (choices: GenerateChoices) => void;
} {
    return {
        initial: lastSubmitted ?? defaults,
        remember: (choices) => {
            lastSubmitted = { ...choices };
        },
    };
}

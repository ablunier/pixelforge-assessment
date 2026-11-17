import type { Quality, Size } from './pricing';

export type GenerationStatus =
    | 'pending'
    | 'queued'
    | 'processing'
    | 'succeeded'
    | 'failed';

/** A row of the history, from App\Http\Resources\GenerationResource. */
export type Generation = {
    id: string;
    prompt: string;
    size: Size;
    quality: Quality;
    referenceImages: string[];
    status: GenerationStatus;
    /** Integer nanodollars. */
    maxPrice: number;
    /** Integer nanodollars, once succeeded. */
    price: number | null;
    error: { code: string; message: string | null } | null;
    createdAt: string;
};

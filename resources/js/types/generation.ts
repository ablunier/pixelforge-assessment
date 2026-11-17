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
    /** Where a succeeded Generation's image can be seen; null until succeeded. */
    image: GenerationImage | null;
};

/**
 * A succeeded Generation's image: the stored copy once downloaded, else
 * Pixelforge's signed URL until it expires, then nothing.
 */
export type GenerationImage =
    | { source: 'stored'; url: string; downloadUrl: string; expiresAt: null }
    | {
          source: 'provider';
          url: string;
          downloadUrl: string;
          expiresAt: string;
      }
    | {
          source: 'expired';
          url: null;
          downloadUrl: null;
          expiresAt: string | null;
      };

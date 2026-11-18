<?php

namespace App\Enums;

/**
 * Where the history shows a succeeded Generation's image from.
 */
enum ImageSource: string
{
    /** The copy downloaded to the `generation-images` disk. */
    case Stored = 'stored';

    /** Pixelforge's signed URL, until it expires or the download completes. */
    case Provider = 'provider';

    /** Neither: the signed URL expired before a copy was stored. */
    case Expired = 'expired';
}

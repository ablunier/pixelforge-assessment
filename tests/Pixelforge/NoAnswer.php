<?php

namespace Tests\Pixelforge;

/**
 * Ways a request to Pixelforge gets no usable answer back.
 */
enum NoAnswer
{
    /** Pixelforge can't be reached: the request never got there. */
    case Unreachable;

    /** Pixelforge carried the request out, but its response was lost. */
    case ResponseLost;

    /** Pixelforge carried the request out, but answered with a body this app can't read. */
    case Unreadable;
}

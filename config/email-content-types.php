<?php

use App\Services\PostDynamicContentProvider;

/**
 * The dynamic content sources an email block can pull from, key => provider class.
 *
 * The builder never talks to Post (or any future model) directly — it asks
 * DynamicContentRegistryService for whichever providers are registered here. Adding a
 * Product or Service block later is a new provider class implementing
 * App\Contracts\DynamicContentProvider plus a second line in this array; nothing in
 * the builder, the renderer, or the block editors needs to change.
 */
return [
    'post' => PostDynamicContentProvider::class,
];

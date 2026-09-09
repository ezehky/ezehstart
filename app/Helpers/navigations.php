<?php

/**
 * Get page navigation links for a given key.
 *
 * This is the one place a sidebar entry is declared. Adding a screen means adding a
 * route and an entry here — nothing reads the route list to build a menu.
 *
 * @param  string  $key  The navigation key (e.g., "admin").
 * @param  bool  $strict  Whether to filter links based on strict access rules.
 * @param  bool  $grouped  Whether to return links grouped by "group" key.
 */
function kPageNavigationLinks(string $key = 'admin', bool $strict = true, bool $grouped = false): array
{
    // BUILD ONCE PER REQUEST: the tree resolves every route, so memoize it.
    static $construct = null;

    $construct ??= [
        'admin' => [
            'dashboard' => [
                'label' => 'Overview',
                'link' => route('admin.dashboard'),
                'icon' => 'home',
            ],
            'config' => [
                'label' => 'Configuration',
                'children' => [
                    'site-config' => [
                        'label' => 'Site configuration',
                        'link' => route('admin.site-config'),
                    ],
                    'social-handles' => [
                        'label' => 'Social handles',
                        'link' => route('admin.config.social-handles'),
                    ],
                    'policies' => [
                        'label' => 'Policies',
                        'link' => route('admin.config.policies'),
                    ],
                    'faqs' => [
                        'label' => 'FAQs',
                        'link' => route('admin.config.faqs'),
                    ],
                ],
                'icon' => 'cog-6-tooth',
            ],
            'users' => [
                'label' => 'Users',
                'children' => [
                    'admins' => [
                        'label' => 'Admins',
                        'link' => route('admin.admins'),
                    ],
                    'members' => [
                        'label' => 'Members',
                        'link' => route('admin.members'),
                    ],
                    'unassigned' => [
                        'label' => 'Unassigned',
                        'link' => route('admin.unassigned'),
                    ],
                    'roles' => [
                        'label' => 'Roles',
                        'link' => route('admin.roles'),
                    ],
                    'activity-logs' => [
                        'label' => 'Activity logs',
                        'link' => route('admin.activity-logs'),
                    ],
                ],
                'icon' => 'users',
            ],
            'profile' => [
                'label' => 'Profile',
                'link' => route('admin.profile'),
                'icon' => 'user-circle',
            ],
        ],
        'user' => [
            'dashboard' => [
                'label' => 'My View',
                'link' => route('user.dashboard'),
                'icon' => 'rectangle-group',
            ],
            'account' => [
                'label' => 'Account',
                'children' => [
                    'account-settings' => [
                        'label' => 'Preferences',
                        'link' => route('user.account-settings'),
                    ],
                    'security-settings' => [
                        'label' => 'Security',
                        'link' => route('user.security-settings'),
                    ],
                    'delete-account' => [
                        'label' => 'Delete account',
                        'link' => route('user.delete-account'),
                    ],
                ],
                'icon' => 'cog-6-tooth',
            ],
            'profile' => [
                'label' => 'Profile',
                'link' => route('user.profile'),
                'icon' => 'user-circle',
            ],
        ],
        'site' => [
            // 'header' => [
            //     'features' => ['label' => 'Features', 'link' => route('home').'#features'],
            //     'contact' => ['label' => 'Contact', 'link' => route('home').'#contact'],
            // ],
        ],
    ];

    // OUTPUT VARIABLE ASSIGNMENT
    if (! $output = data_get($construct, $key)) {
        return [];
    }
    // RESOLVE STRICT MODE
    if ($strict) {
        $output = kNavigationStrictAction($output);
    }

    // RETURN OUTPUT
    return $output;
}

/**
 * Filter navigation links, dropping anything the current page should not show and
 * marking what is active.
 *
 * A branch with a falsy "check" key disappears — that is how a feature behind a
 * site-config switch hides its own menu entry. A parent with no surviving children
 * disappears with them, so the sidebar never shows an empty group.
 *
 * @param  array  $construct  The navigation structure.
 * @return array Filtered navigation links.
 */
function kNavigationStrictAction(array $construct): array
{
    if (! $construct) {
        return [];
    }
    // ||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    $output = [];
    // |||
    foreach ($construct as $index => $item) {
        // RESOLVE CHECK KEY VALUE.
        if (! data_get($item, 'check', true)) {
            continue;
        }
        // ||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
        // ASSIGN CHILDREN
        $children = data_get($item, 'children');

        // ASSIGN LINK
        $link = data_get($item, 'link');

        // ASSIGN ICON
        $item['icon'] = data_get($item, 'icon', 'stop');

        // Spa
        $item['spa'] = data_get($item, 'spa', true);

        // RESOLVE PARENT ACTIVE LINK
        $item['active'] = kCheckActiveTitle($index);

        // ||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
        // CHILDREN
        if ($children) {
            foreach ($children as $key => $child) {
                // RESOLVE EMPTY LINKS AND CHILD CHECK KEY
                if (
                    ! data_get($child, 'check', true) ||
                    ! data_get($child, 'link')
                ) {
                    continue;
                }

                // RESOLVE CHILD SPA
                $item['children'][$key]['spa'] = data_get($child, 'spa', true);

                // RESOLVE CHILD ACTIVE LINK
                $item['children'][$key]['active'] = $item['active'] && kCheckActiveTitle($key, false);
            }
            // CHECK IF CHILDREN IS EMPTY
            if (! data_get($item, 'children')) {
                continue;
            }
        }
        // CHECK PARENT LINK
        elseif (! $link) {
            continue;
        }

        // ASSIGN ITEM TO OUTPUT
        $output[$index] = $item;
    }

    return $output;
}

/**
 * Check if a page title matches the given page slug.
 *
 * @param  string  $page  The page slug to check.
 * @param  bool  $checkParent  Whether to check parent title or child.
 * @param  string  $pageTitle  Optional full page title to compare against.
 */
function kCheckActiveTitle(string $page, bool $checkParent = true, string $pageTitle = ''): bool
{
    $pageTitle = explode(' / ', $pageTitle ?: config('_setups.title'));

    return $checkParent ?
        kTextCompare(kSlug($pageTitle[0]), $page) :
        kTextCompare(kSlug(data_get($pageTitle, 1, '')), $page);
}

// |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
// SITE TITLE

/**
 * Set the site title with optional child, grandchild, print, and subtitle.
 *
 * The title is not just a heading: kCheckActiveTitle() reads it back to decide which
 * sidebar entry is highlighted, so the parent segment must match the nav key.
 *
 * @param  string|null  $print  Alternative title for printing.
 * @param  string|null  $subtitle  Optional subtitle.
 * @param  bool  $format  Title-case the segments. Pass false for hand-written
 *                        titles — title-casing mangles acronyms ("UI/UX" -> "Ui/Ux").
 */
function kSetSiteTitle(string $parent, ?string $child = null, ?string $grandchild = null, ?string $print = null, ?string $subtitle = null, bool $format = true): void
{
    // CREATE ARRAY
    $titleArray = ['parent' => $parent, 'child' => $child, 'grandchild' => $grandchild];
    // SETUP TITLE
    $title = collect($titleArray)
        ->map(fn ($value) => $format ? kBreakText($value) : $value)
        ->filter()
        ->implode(' / ');
    // ||||||||||||||||||||||||||||||||||||||||||||||||||||||
    $data = ['_setups.title' => $title];
    // TITLE PRINT ALTERNATIVE: for title print
    if ($print) {
        $data['_setups.title-print-alternative'] = $print;
    }
    // SUBTITLE
    if ($subtitle) {
        $data['_setups.subtitle'] = $subtitle;
    }
    // CHECK AND ADD TITLE TO METADATA
    if (! config('_setups.metadata.title')) {
        kSetMetaData(title: $data['_setups.title']);
    }
    // ASSIGN DATA TO CONFIG
    config($data);
}

if (! function_exists('kSetMetaData')) {
    /**
     * Set or override page metadata in config(_setups.metadata).
     *
     * @param  mixed  ...$rest  Associative key/value metadata overrides.
     */
    function kSetMetaData(...$rest): void
    {
        $currentData = [
            'title' => config('_setups.metadata.title'),
            'key' => config('_setups.metadata.key'),
            'description' => config('_setups.metadata.description'),
            'image' => config('_setups.metadata.image'),
            'type' => 'site',
            'url' => config('_setups.metadata.url') ?? config('app.url'),
        ];
        // ASSIGN TO LARAVEL CONFIG
        config(['_setups.metadata' => [...$currentData, ...$rest]]);
    }
}

/**
 * Deconstruct the current site title into an associative array.
 *
 * @return array ['parent' => string, 'child' => string|null, 'grandchild' => string|null]
 */
function kDestructSiteTitle(): array
{
    // GET CURRENT TITLE
    $currentTitle = config('_setups.title');
    $destruct = explode(' / ', $currentTitle);
    // MAKE ASSOCIATIVE ARRAY
    $combine = array_combine(['parent', 'child', 'grandchild'], array_pad($destruct, 3, null));

    // RETURN
    return collect($combine)->filter()->toArray();
}

/**
 * Return the site title for printing purposes.
 */
function kPrintSiteTitle(): string
{
    // RETURN ALTERNATIVE
    if ($alternative = config('_setups.title-print-alternative')) {
        return $alternative;
    }
    // USE ACTUAL TITLE
    $destruct = kDestructSiteTitle();
    // CHECK IF EMPTY
    if (! $destruct) {
        return '';
    }

    $parent = $destruct['parent'] ?? '';
    $child = $destruct['child'] ?? null;
    $grandchild = $destruct['grandchild'] ?? null;

    // RETURN: the deepest two segments, so a long trail stays readable
    return match (true) {
        $grandchild !== null => $child.' / '.$grandchild,
        $child !== null => $parent.' / '.$child,
        default => $parent,
    };
}

/**
 * Update the site title dynamically.
 *
 * @param  mixed  ...$rest  Additional title segments.
 */
function kUpdateSiteTitle(...$rest): void
{
    // GET DESTRUCT
    $title = kDestructSiteTitle();
    // UPDATE TITLE CONFIG
    kSetSiteTitle(...[...$title, ...$rest]);
}

/**
 * Pause the display of the site title.
 */
function kPauseSiteTitle(): void
{
    config(['_setups.show-title' => false]);
}

// ||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
// BACK FORWARD LINKS

/**
 * Set the "back" or "forward" link configuration.
 */
function kBackForwardLink(string $routeName, array $params = [], bool $forward = false, string $label = 'Back', bool $spa = true): void
{
    $array = [
        'route' => route($routeName, $params),
        'label' => $label,
        'spa' => $spa,
        'forward' => $forward,
    ];
    config(['_setups.back-forward-link' => $array]);
}

// ||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
// LINK BUILDERS

/**
 * Build navigation links from an array of values.
 *
 * @param  array  $data  Array of values for the links.
 * @param  string  $route  Route name.
 * @param  string  $param  Route parameter key.
 */
function kBuildLink(array $data, string $route, string $param): array
{
    $links = [];
    foreach ($data as $value) {
        // CHECK VALUE
        if (! $value) {
            continue;
        }
        // BUILD LINK
        $links[$value] = [
            'label' => kBreakText($value),
            'link' => route($route, [$param => $value]),
        ];
    }

    return $links;
}

/**
 * Generate a pre-configured link set based on a name.
 *
 * Register a tab strip once in $configMap and every page that renders it stays in
 * step — a filter bar built from an enum, say.
 */
function kLinkBuilder(string $name): array
{
    $links = [];

    $configMap = [

    ];

    if (! $config = data_get($configMap, $name)) {
        return $links;
    }

    // Add links from main data
    $links = array_merge($links, kBuildLink($config['data'], $config['route'], $config['param']));

    return $links;
}

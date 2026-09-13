<?php

use App\Enums\CategoryGroupEnum;

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
                    'site-info' => [
                        'label' => 'Site info',
                        'link' => route('admin.config.site'),
                    ],
                    'security' => [
                        'label' => 'Security',
                        'link' => route('admin.config.security'),
                    ],
                    'preferences' => [
                        'label' => 'Preferences',
                        'link' => route('admin.config.preferences'),
                    ],
                    'social-handles' => [
                        'label' => 'Social handles',
                        'link' => route('admin.config.social-handles'),
                    ],
                    'json' => [
                        'label' => 'JSON Editor',
                        'link' => route('admin.config.json'),
                    ],
                    'policies' => [
                        'label' => 'Policies',
                        'link' => route('admin.config.policies'),
                    ],
                    'faqs' => [
                        'label' => 'FAQs',
                        'link' => route('admin.config.faqs'),
                    ],
                    'notification-types' => [
                        'label' => 'Notification types',
                        'link' => route('admin.config.notification-types'),
                    ],
                ],
                'icon' => 'cog-6-tooth',
            ],
            'content' => [
                'label' => 'Content',
                'children' => [
                    'blogs' => [
                        'label' => 'Posts',
                        'link' => route('admin.blog.blogs'),
                    ],
                    'categories' => [
                        'label' => 'Categories',
                        'link' => route('admin.categories', ['category_group' => CategoryGroupEnum::BLOG]),
                    ],
                    'tags' => [
                        'label' => 'Tags',
                        'link' => route('admin.tags'),
                    ],
                    'image-library' => [
                        'label' => 'Image library',
                        'link' => route('admin.image-library'),
                    ],
                    'video-library' => [
                        'label' => 'Video library',
                        'link' => route('admin.video-library'),
                    ],
                ],
                'icon' => 'newspaper',
            ],
            'transactions' => [
                'label' => 'Transactions',
                'link' => route('admin.transactions'),
                'icon' => 'banknotes',
            ],
            'users' => [
                'label' => 'Users',
                'children' => [
                    'admins' => [
                        'label' => 'Admins',
                        'link' => route('admin.admins'),
                    ],
                    'users-list' => [
                        'label' => 'Users List',
                        'link' => route('admin.users'),
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
            'transactions' => [
                'label' => 'Transactions',
                'link' => route('user.transactions'),
                'icon' => 'banknotes',
            ],
            'image-library' => [
                'label' => 'Image library',
                'link' => route('user.image-library'),
                'icon' => 'photo',
            ],
            'video-library' => [
                'label' => 'Video library',
                'link' => route('user.video-library'),
                'icon' => 'film',
            ],
            'profile' => [
                'label' => 'Profile',
                'link' => route('user.profile'),
                'icon' => 'user-circle',
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
        $output = kNavigationStrictAction($output, $key);
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
 * In the admin workspace a branch also disappears when the signed-in account has no
 * gate over it. This is the *same* question `UserService::pageAccess()` asks on the
 * way into the page, which is what stops a page being visible but forbidden, or
 * reachable but hidden. It is a courtesy, not the boundary: hiding a link protects
 * nothing on its own, so the page still refuses the request itself.
 *
 * @param  array  $construct  The navigation structure.
 * @param  string  $key  The navigation set being filtered. Only 'admin' is gated.
 * @return array Filtered navigation links.
 */
function kNavigationStrictAction(array $construct, string $key = ''): array
{
    if (! $construct) {
        return [];
    }
    // ||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // GATES ONLY APPLY TO THE ADMIN WORKSPACE, AND ONLY TO A SIGNED-IN ACCOUNT.
    $gated = $key === 'admin' && auth()->check();
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

        // RESOLVE GATE. Only a leaf answers for itself. A branch is decided by its
        // children — it survives if any of them do, which the empty-children check
        // further down is what enforces. Gating the branch here as well would hide a
        // group whose child was granted on its own.
        if ($gated && ! $children && ! kGate($index)) {
            continue;
        }

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
            // REBUILT RATHER THAN FILTERED IN PLACE: a child that fails its check or
            // its gate has to actually leave the array. Skipping over it only misses
            // the spa and active keys — the entry itself would still be rendered.
            $item['children'] = [];

            foreach ($children as $childKey => $child) {
                // RESOLVE EMPTY LINKS AND CHILD CHECK KEY
                if (
                    ! data_get($child, 'check', true) ||
                    ! data_get($child, 'link')
                ) {
                    continue;
                }

                // RESOLVE CHILD GATE
                if ($gated && ! kGate("{$index}.{$childKey}")) {
                    continue;
                }

                // RESOLVE CHILD SPA
                $child['spa'] = data_get($child, 'spa', true);

                // RESOLVE CHILD ACTIVE LINK
                $child['active'] = $item['active'] && kCheckActiveTitle($childKey, false);

                $item['children'][$childKey] = $child;
            }
            // CHECK IF CHILDREN IS EMPTY
            if (! $item['children']) {
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
// BREADCRUMB

/**
 * The trail of the current page, read back out of the site title.
 *
 * kSetSiteTitle() already names where a page sits — "Users / users" — and the
 * sidebar tree already knows what each of those segments links to. This walks the
 * one against the other rather than asking a page to declare its ancestry twice,
 * which is what keeps a renamed menu entry from leaving a stale crumb behind.
 *
 * A segment that matches nothing in the tree still appears, unlinked. That is the
 * common case for a record's own name — "Users / users / Ada Lovelace" — and it
 * is also the graceful failure when a title segment and a nav key drift apart.
 *
 * @param  string|null  $key  Workspace tree to resolve against. Defaults to the one
 *                            the current route belongs to.
 * @return array<int, array{label: string, link: string|null, icon: string|null}>
 */
function kBreadcrumbTrail(?string $key = null): array
{
    $segments = kDestructSiteTitle();

    if (! $segments) {
        return [];
    }

    $key ??= str_starts_with((string) request()->route()?->getName(), 'admin.') ? 'admin' : 'user';

    // Unfiltered: a crumb is a description of where the page is, and the page has
    // already been gated on the way in. Running the strict filter here would drop
    // the parent of a screen reached by a direct link and orphan the trail.
    $branch = kPageNavigationLinks($key, strict: false);

    $trail = [];

    foreach ($segments as $segment) {
        $slug = kSlug($segment);

        // The tree is keyed by slug, but a label that was written out in full —
        // "Activity logs" against a key of "activity-logs" — matches either way.
        $match = data_get($branch, $slug) ?: collect($branch)
            ->first(fn ($entry) => is_array($entry) && kTextCompare(kSlug($entry['label'] ?? ''), $slug));

        $link = data_get($match, 'link');

        // A parent has no screen of its own — "Users" is a heading over Admins, Roles
        // and the rest — and a crumb that cannot be clicked is a crumb that looks
        // broken. It goes where the sidebar entry goes: the first of its children this
        // account can actually open, taken from the gated tree so it never points at a
        // page that would answer 404.
        if (! $link && data_get($match, 'children')) {
            $link = collect(data_get(kPageNavigationLinks($key, strict: true), "{$slug}.children", []))
                ->pluck('link')
                ->filter()
                ->first();
        }

        $trail[] = [
            'label' => $segment,
            'link' => $link,
            'icon' => data_get($match, 'icon'),
        ];

        // Descend, so "users" is looked for under "users" rather than at the root.
        $branch = data_get($match, 'children', []);
    }

    // The page you are on is not somewhere to navigate to.
    $trail[array_key_last($trail)]['link'] = null;

    return $trail;
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

{{-- Google Analytics 4, from AnalyticsService::tag() (a view composer hands
     it over as $analytics; null prints nothing).

     With consent already given the tag loads at once; otherwise it waits for
     the banner's cookie-consent-updated event, and a visitor who changes their
     mind later loads it on that same event without a reload. A visitor who has
     withdrawn consent has the _ga cookies GA left behind cleared on their next
     page. Page views only — nothing about the visitor is sent. --}}
@if ($analytics)
    <script>
        (() => {
            const id = @js($analytics['id']);
            let loaded = false;

            window.dataLayer = window.dataLayer || [];

            const load = () => {
                if (loaded) return;
                loaded = true;
                window.gtag = function () { window.dataLayer.push(arguments); };
                window.gtag('js', new Date());
                window.gtag('config', id);

                const script = document.createElement('script');
                script.async = true;
                script.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(id);
                document.head.appendChild(script);
            };

            @if ($analytics['granted'])
                load();
            @else
                document.cookie.split('; ')
                    .map((row) => row.split('=')[0])
                    .filter((name) => name === '_ga' || name.startsWith('_ga_'))
                    .forEach((name) => {
                        const host = location.hostname.split('.').slice(-2).join('.');
                        document.cookie = `${name}=; path=/; max-age=0`;
                        document.cookie = `${name}=; path=/; max-age=0; domain=.${host}`;
                    });

                window.addEventListener('cookie-consent-updated', (event) => {
                    if ((event.detail || []).includes('measurement')) load();
                });
            @endif
        })();
    </script>
@endif

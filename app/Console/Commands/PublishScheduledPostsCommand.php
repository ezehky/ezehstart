<?php

namespace App\Console\Commands;

use App\Enums\StatusPost;
use App\Models\Post;
use App\Services\BlogService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class PublishScheduledPostsCommand extends Command
{
    protected $signature = 'blog:publish-scheduled
                            {--post=* : Publish these post ids, ignoring the scheduled date}
                            {--no-announce : Publish without telling subscribers}
                            {--dry-run : Report what would be published without changing anything}';

    protected $description = 'Publish posts whose scheduled date has arrived and tell the subscribers';

    protected bool $dryRun = false;

    public function handle(): int
    {
        $this->dryRun = (bool) $this->option('dry-run');

        $announce = ! $this->option('no-announce');
        $service = app(BlogService::class);
        $published = 0;
        $notified = 0;

        $this->duePosts()->each(function (Post $post) use ($service, $announce, &$published, &$notified) {
            if ($this->dryRun) {
                $published++;

                $this->report($post, null);

                return;
            }

            try {
                $service->applyStatus($post, StatusPost::PUBLISHED);
                $post->save();

                // Announced after the save, so a post nobody can reach yet is
                // never the subject of an email pointing at it.
                $reached = $announce ? $service->announce($post) : null;
            } catch (\Throwable $exception) {
                // One post with a broken relation must not hold up the queue.
                Log::channel('ezeh')->error('Scheduled post failed to publish', [
                    'post_id' => $post->id,
                    'message' => $exception->getMessage(),
                ]);

                $this->line(sprintf('  <fg=red>failed</> · %s · %s', $post->title, $exception->getMessage()));

                return;
            }

            $published++;
            $notified += $reached ?? 0;

            $this->report($post, $reached);
        });

        $this->info(sprintf(
            '%s %d post(s), reaching %d subscriber(s).',
            $this->dryRun ? 'Would publish' : 'Published',
            $published,
            $notified,
        ));

        return self::SUCCESS;
    }

    /**
     * Posts whose moment has come.
     *
     * The --post list bypasses the date but not the status: a draft somebody
     * typed the id of is not something this command publishes on their behalf.
     *
     * @return Collection<int, Post>
     */
    protected function duePosts(): Collection
    {
        $ids = (array) $this->option('post');

        return Post::query()
            ->when(
                $ids,
                fn ($query) => $query->where('status', StatusPost::SCHEDULED)->whereIn('id', $ids),
                fn ($query) => $query->dueForPublishing(),
            )
            ->orderBy('published_at')
            ->get();
    }

    /**
     * One line per post, saying how far the announcement got.
     */
    protected function report(Post $post, ?int $reached): void
    {
        $this->line(sprintf(
            '  <fg=green>published</> · %s · %s',
            $post->title,
            $reached === null ? 'not announced' : $reached.' subscriber(s)',
        ));
    }
}

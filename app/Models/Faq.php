<?php

namespace App\Models;

use App\Enums\FaqTypeEnum;
use App\Enums\StatusDefault;
use App\Traits\WithDynamicModelFormatting;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Unguarded]
class Faq extends Model
{
    use WithDynamicModelFormatting;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'faq_type' => FaqTypeEnum::class,
            'status' => StatusDefault::class,
        ];
    }

    // Getters

    /**
     * The answer, ready to print. It is written in the rich-text editor and passed
     * through BlogService::sanitize() on the way in — from the screen, an import or
     * a seeder — so what is stored is already safe to render unescaped.
     */
    public function answerHtml(): string
    {
        return (string) $this->answer;
    }

    /**
     * A short form of the question, for an activity log line that has to sit on
     * one row next to everything else.
     */
    public function label(): string
    {
        return Str::limit($this->question, 60);
    }

    // Scopes

    /**
     * The order the questions are shown in. Falls back to id so two questions
     * sharing an order still come out in a stable sequence rather than whatever
     * the database felt like that day.
     */
    #[Scope]
    protected function inFlowOrder(Builder $query): void
    {
        $query->orderBy('flow_order')->orderBy('id');
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', StatusDefault::ACTIVE);
    }
}

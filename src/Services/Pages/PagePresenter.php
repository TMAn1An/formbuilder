<?php

namespace TMAn1An\FormBuilder\Services\Pages;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use TMAn1An\FormBuilder\Enums\FormAvailabilityState;
use TMAn1An\FormBuilder\Enums\PageBlockType;
use TMAn1An\FormBuilder\FormBuilder;
use TMAn1An\FormBuilder\Models\Page;
use TMAn1An\FormBuilder\Models\PageBlock;
use TMAn1An\FormBuilder\Services\Forms\FormAvailability;
use TMAn1An\FormBuilder\Services\Forms\FormPresenter;
use TMAn1An\FormBuilder\Styling\ButtonStyleSchema;
use TMAn1An\FormBuilder\Styling\PageStyleSchema;
use TMAn1An\FormBuilder\Styling\StyleProperties;
use TMAn1An\FormBuilder\Support\CssScoper;
use TMAn1An\FormBuilder\Support\HtmlSanitizer;

/**
 * Assembles everything resources/views/pages/_renderer.blade.php needs, so
 * the Blade stays presentation-only. Inline styles are rebuilt here from
 * re-checked values (colors re-matched against #rrggbb, numbers clamped,
 * enums re-checked) -- stored settings are never echoed into CSS as-is.
 *
 * Form blocks render through the SAME FormPresenter + renderer partial as
 * /forms/{slug}; the page only holds a reference to the form. A form that
 * isn't publicly available renders nothing on the public page (and a
 * notice in the admin preview).
 */
class PagePresenter
{
    use StyleProperties;

    public function __construct(
        private readonly FormPresenter $forms,
        private readonly FormAvailability $availability,
        private readonly HtmlSanitizer $html,
        private readonly CssScoper $css,
        private readonly FormBuilder $fb,
    ) {}

    /**
     * @param  list<int>  $sessionSubmittedFormIds
     * @return array<string, mixed>
     */
    public function present(Page $page, ?Authenticatable $viewer, array $sessionSubmittedFormIds = [], ?int $justSubmittedFormId = null, bool $preview = false): array
    {
        $blocks = $page->blocks()->with(['form', 'media'])->get();
        $children = $blocks->whereNotNull('parent_id')->groupBy('parent_id');
        $context = compact('page', 'viewer', 'sessionSubmittedFormIds', 'justSubmittedFormId', 'preview');
        $scope = '#'.$page->wrapperId();

        return [
            'page' => $page,
            'wrapperId' => $page->wrapperId(),
            'styleSheet' => trim($scope.'{'.PageStyleSchema::cssDeclarations($page->style_settings).'}'."\n".$this->css->scope($page->custom_css, $scope)),
            'blocks' => $blocks->whereNull('parent_id')
                ->map(fn (PageBlock $block) => $this->presentBlock($block, $children->get($block->id, collect()), $context))
                ->filter()
                ->values()
                ->all(),
        ];
    }

    /** @param  Collection<int, PageBlock>  $children */
    private function presentBlock(PageBlock $block, Collection $children, array $context): ?array
    {
        $s = $block->settings ?? [];
        $view = ['id' => $block->id, 'type' => $block->type->value, 'style' => '', 'settings' => $s];

        switch ($block->type) {
            case PageBlockType::Section:
            case PageBlockType::Columns:
                $slots = PageBlockSettingsSchema::slotCount($block->type, $s);
                $view['slots'] = array_fill(0, $slots, []);
                foreach ($children as $child) {
                    if ($child->slot < $slots && ($presented = $this->presentBlock($child, collect(), $context)) !== null) {
                        $view['slots'][$child->slot][] = $presented;
                    }
                }
                $view['style'] = $block->type === PageBlockType::Section
                    ? $this->css([
                        'background-color' => $this->color($s['background_color'] ?? null),
                        'color' => $this->color($s['text_color'] ?? null),
                        'padding' => $this->px($s['padding_y'] ?? 32, 0, 160).' '.$this->px($s['padding_x'] ?? 24, 0, 120),
                        'border-radius' => $this->px($s['radius'] ?? null, 0, 200),
                    ])
                    : $this->css([
                        '--pb-cols' => (string) $slots,
                        '--pb-col-gap' => $this->px($s['gap'] ?? 24, 0, 80),
                        'align-items' => $this->enum($s['vertical_align'] ?? null, ['start' => 'start', 'center' => 'center', 'end' => 'end']),
                    ]);
                break;

            case PageBlockType::Heading:
                $view['tag'] = $this->enum($s['level'] ?? 'h2', array_combine($l = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], $l)) ?? 'h2';
                $view['style'] = $this->css([
                    'text-align' => $this->align($s['align'] ?? null),
                    'font-size' => $this->px($s['font_size'] ?? null, 10, 120),
                    'font-weight' => $this->enum((string) ($s['font_weight'] ?? ''), array_combine($w = ['400', '500', '600', '700', '800'], $w)),
                    'color' => $this->color($s['color'] ?? null),
                    'margin-top' => $this->px($s['margin_top'] ?? null, 0, 160),
                    'margin-bottom' => $this->px($s['margin_bottom'] ?? null, 0, 160),
                ]);
                break;

            case PageBlockType::Paragraph:
            case PageBlockType::Html:
                $view['html'] = $this->html->sanitize($s['content'] ?? null);
                $view['style'] = $this->css([
                    'text-align' => $this->align($s['align'] ?? null),
                    'font-size' => $this->px($s['font_size'] ?? null, 10, 120),
                    'color' => $this->color($s['color'] ?? null),
                ]);
                break;

            case PageBlockType::Image:
                if ($block->media === null) {
                    return $context['preview'] ? $view + ['missing' => 'This image block has no image.'] : null;
                }
                $view['media'] = ['url' => $block->media->url(), 'width' => $block->media->width, 'height' => $block->media->height, 'alt' => (string) ($s['alt'] ?? '')];
                $view['link'] = isset($s['link_url']) && PageBlockSettingsSchema::isSafeUrl($s['link_url']) ? $s['link_url'] : null;
                // Flexbox, not text-align: hosts often set img { display:block }.
                $view['style'] = $this->css(['justify-content' => $this->enum($s['align'] ?? 'center', ['left' => 'flex-start', 'center' => 'center', 'right' => 'flex-end'])]);
                $view['imageStyle'] = $this->css([
                    'width' => $this->length($s['width'] ?? null),
                    'max-width' => $this->length($s['max_width'] ?? null) ?? '100%',
                    'border-radius' => $this->px($s['radius'] ?? null, 0, 200),
                ]);
                break;

            case PageBlockType::Button:
                $view['link'] = PageBlockSettingsSchema::isSafeUrl((string) ($s['url'] ?? '')) ? $s['url'] : '#';
                $view['style'] = ButtonStyleSchema::cssDeclarations($s['style'] ?? null);
                break;

            case PageBlockType::Divider:
                $view['style'] = $this->css([
                    'border-top' => $this->px($s['thickness'] ?? 1, 1, 20).' solid '.($this->color($s['color'] ?? null) ?? '#dde3ea'),
                    'width' => $this->length($s['width'] ?? null),
                    'margin' => $this->px($s['margin_y'] ?? 8, 0, 160).' auto',
                ]);
                break;

            case PageBlockType::Spacer:
                $view['style'] = $this->css(['height' => $this->px($s['height'] ?? 32, 0, 400)]);
                break;

            case PageBlockType::Form:
                return $this->presentFormBlock($block, $view, $context);
        }

        return $view;
    }

    private function presentFormBlock(PageBlock $block, array $view, array $context): ?array
    {
        $form = $block->form;
        if ($form === null) {
            return $context['preview'] ? $view + ['missing' => 'The form this block referenced no longer exists.'] : null;
        }

        $state = $this->availability->check($form, $context['viewer'], $context['sessionSubmittedFormIds']);

        if ($state === FormAvailabilityState::Unavailable) {
            return $context['preview']
                ? $view + ['missing' => "The form \"{$form->name}\" is {$form->status->label()} and won't appear on the public page until it is published (and public)."]
                : null;
        }

        $open = $state === FormAvailabilityState::Open;

        return $view + ['form' => [
            'presented' => $this->forms->present($form),
            'action' => $open && ! $context['preview'] ? $this->fb->public('forms.submit', $form) : null,
            'notice' => $open ? null : $state->message(),
            'success' => $context['justSubmittedFormId'] === $form->id ? $form->setting('success_message') : null,
            'embedPageId' => $context['page']->id,
            'hideTitle' => (bool) ($view['settings']['hide_title'] ?? false),
            'hideDescription' => (bool) ($view['settings']['hide_description'] ?? false),
        ]];
    }

    /* ----- re-checked CSS value helpers (never echo stored strings as-is) ----- */

    /** @param  array<string, string|null>  $declarations */
    private function css(array $declarations): string
    {
        $out = [];
        foreach ($declarations as $property => $value) {
            if ($value !== null && $value !== '') {
                $out[] = $property.':'.$value;
            }
        }

        return implode(';', $out);
    }

    private function color(mixed $value): ?string
    {
        return is_string($value) && preg_match(self::COLOR_PATTERN, $value) ? strtolower($value) : null;
    }

    private function px(mixed $value, int $min, int $max): ?string
    {
        return is_numeric($value) ? max($min, min($max, (int) $value)).'px' : null;
    }

    private function align(mixed $value): ?string
    {
        return $this->enum($value, ['left' => 'left', 'center' => 'center', 'right' => 'right']);
    }

    private function enum(mixed $value, array $map): ?string
    {
        return is_string($value) && isset($map[$value]) ? $map[$value] : null;
    }

    private function length(mixed $value): ?string
    {
        return self::cleanLength(['max' => PageBlockSettingsSchema::IMAGE_LENGTH_MAX], $value);
    }
}

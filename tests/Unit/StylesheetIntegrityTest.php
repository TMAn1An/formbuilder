<?php

namespace TMAn1An\FormBuilder\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TMAn1An\FormBuilder\FormBuilder;

/**
 * Regression: a comment in forms.css once contained "h*\/p", which ended the
 * comment early -- browsers then discarded every rule after it, including
 * the whole submit-button rule, so button sizing silently did nothing.
 * Every shipped stylesheet must parse cleanly and keep its key rules.
 */
class StylesheetIntegrityTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function stylesheets(): array
    {
        $files = glob(FormBuilder::distPath('css').'/*.css');

        return array_combine(array_map('basename', $files), array_map(fn ($f) => [$f], $files));
    }

    #[DataProvider('stylesheets')]
    public function test_stylesheet_has_no_stray_comment_terminators_and_balanced_braces(string $file): void
    {
        $css = (string) file_get_contents($file);
        $withoutComments = (string) preg_replace('#/\*.*?\*/#s', '', $css);

        $this->assertStringNotContainsString('*/', $withoutComments, basename($file).' has a "*/" outside a comment (a comment ended early).');
        $this->assertStringNotContainsString('/*', $withoutComments, basename($file).' has an unterminated comment.');
        $this->assertSame(substr_count($withoutComments, '{'), substr_count($withoutComments, '}'), basename($file).' has unbalanced braces.');
    }

    public function test_button_rule_is_real_css_not_swallowed_by_a_comment(): void
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(FormBuilder::distPath('css/forms.css')));

        $this->assertMatchesRegularExpression('/(^|\})\s*\.ff-button\s*\{[^}]*width:\s*var\(--ff-btn-width/s', $css);
        $this->assertMatchesRegularExpression('/\.ff-button\s*\{[^}]*min-height:\s*var\(--ff-btn-min-height/s', $css);
        $this->assertMatchesRegularExpression('/\.ff-form \.ff-actions\s*\{[^}]*justify-content:\s*var\(--ff-btn-align/s', $css);
    }
}

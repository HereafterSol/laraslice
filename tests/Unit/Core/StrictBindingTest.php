<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Unit\Core;

use LaraSlice\Core\Base\BaseFormBusinessObject;
use LaraSlice\Core\Discovery\UpdateSemantics;
use PHPUnit\Framework\TestCase;

/**
 * Strict binding (Proposal #6): a provided value that does
 * not fit its declared property type is RECORDED as a
 * per-issue error on the form object — the evidence the base
 * controllers reject the write with — instead of being
 * silently dropped by fromArray() while the property keeps
 * its default.
 *
 * Pre-Phase-D, fromArray() recorded nothing: every test here
 * that asserts bindingIssues() fails by ABSENCE (the method
 * did not exist), and the value-level assertions document
 * the old silent-drop behaviour they replace.
 *
 * Legitimate representations keep binding with NO issue:
 * integer strings for ints, numeric strings for floats,
 * HTML boolean words for bools, scalars for strings, the
 * empty-string companion for arrays, and blank (null/'')
 * values for anything — blanks are form semantics, handled
 * by defaults and validation, not by the binding layer.
 */
final class StrictBindingTest extends TestCase
{
    public function test_garbage_for_an_int_is_an_issue_not_a_silent_drop(): void
    {
        $form = StrictBindingProbeForm::fromArray(['quantity' => 'abc']);

        $this->assertSame([null], [$form->quantity]); // property keeps its default
        $this->assertSame([], $form->providedFields()); // and does not count as provided
        $issues = $form->bindingIssues();
        $this->assertCount(1, $issues);
        $this->assertSame('quantity', $issues[0]['field']);
        $this->assertStringContainsString("'abc'", $issues[0]['problem']);
        $this->assertStringContainsString('integer', $issues[0]['expected']);
    }

    public function test_fractional_value_for_an_int_is_an_issue(): void
    {
        $form = StrictBindingProbeForm::fromArray(['quantity' => '4.5']);

        $this->assertCount(1, $form->bindingIssues());
        $this->assertSame('quantity', $form->bindingIssues()[0]['field']);
    }

    public function test_garbage_for_a_bool_is_an_issue(): void
    {
        $form = StrictBindingProbeForm::fromArray(['active' => 'banana']);

        $this->assertFalse($form->active);
        $this->assertCount(1, $form->bindingIssues());
        $this->assertSame('active', $form->bindingIssues()[0]['field']);
    }

    public function test_array_for_a_string_is_an_issue(): void
    {
        $form = StrictBindingProbeForm::fromArray(['name' => ['x']]);

        $this->assertCount(1, $form->bindingIssues());
        $this->assertSame('name', $form->bindingIssues()[0]['field']);
    }

    public function test_scalar_for_an_array_is_an_issue(): void
    {
        $form = StrictBindingProbeForm::fromArray(['tags' => 'just-a-string']);

        $this->assertSame([], $form->tags);
        $this->assertCount(1, $form->bindingIssues());
        $this->assertSame('tags', $form->bindingIssues()[0]['field']);
    }

    public function test_multiple_bad_values_each_get_their_own_issue(): void
    {
        $form = StrictBindingProbeForm::fromArray(['quantity' => 'abc', 'price' => 'soon', 'name' => 'fine']);

        $fields = array_column($form->bindingIssues(), 'field');
        sort($fields);
        $this->assertSame(['price', 'quantity'], $fields);
        $this->assertSame('fine', $form->name);
    }

    public function test_legitimate_representations_bind_without_issues(): void
    {
        $form = StrictBindingProbeForm::fromArray([
            'name' => 42,               // scalar → string
            'quantity' => '42',         // integer string → int
            'price' => '12.50',         // numeric string → float
            'active' => 'yes',          // HTML boolean word → bool
            'tags' => '',               // empty companion → empty array
        ]);

        $this->assertSame([], $form->bindingIssues());
        $this->assertSame('42', $form->name);
        $this->assertSame(42, $form->quantity);
        $this->assertSame(12.5, $form->price);
        $this->assertTrue($form->active);
        $this->assertSame([], $form->tags);
        $this->assertEqualsCanonicalizing(
            ['name', 'quantity', 'price', 'active', 'tags'],
            $form->providedFields()
        );
    }

    public function test_json_native_values_bind_without_issues(): void
    {
        $form = StrictBindingProbeForm::fromArray([
            'quantity' => 7,
            'price' => 3.25,
            'active' => false,
            'tags' => ['a', 'b'],
        ]);

        $this->assertSame([], $form->bindingIssues());
        $this->assertSame(7, $form->quantity);
        $this->assertSame(3.25, $form->price);
        $this->assertFalse($form->active);
        $this->assertSame(['a', 'b'], $form->tags);
    }

    public function test_blank_values_are_never_binding_issues(): void
    {
        $form = StrictBindingProbeForm::fromArray([
            'quantity' => '',
            'price' => null,
            'name' => '',
        ]);

        $this->assertSame([], $form->bindingIssues());
    }

    public function test_unknown_keys_stay_ignored_and_issue_free(): void
    {
        // Requests carry framework keys (_token, _method) and
        // occasionally misspellings; the binding layer's
        // contract covers DECLARED properties only.
        $form = StrictBindingProbeForm::fromArray(['_token' => 'x', 'quantitiy' => '5', 'name' => 'ok']);

        $this->assertSame([], $form->bindingIssues());
        $this->assertSame('ok', $form->name);
    }

    public function test_binding_issues_do_not_leak_into_to_array(): void
    {
        $form = StrictBindingProbeForm::fromArray(['quantity' => 'abc']);

        $this->assertArrayNotHasKey('bindingIssues', $form->toArray());
    }

    public function test_name_title_bridge_only_moves_scalars_and_marks_them_provided(): void
    {
        // The bridge fills `name` from the `title` alias…
        $form = StrictBindingProbeForm::fromArray(['title' => 'Hello']);
        $this->assertSame('Hello', $form->name);
        // …and the bridged field counts as provided, so a
        // replace-completeness check cannot flag a field the
        // caller effectively sent (goldfish round).
        $this->assertContains('name', $form->providedFields() ?? []);

        // A non-scalar under the alias key is NOT cast into
        // the string "Array" — the bridge skips it, exactly
        // like an unknown key (goldfish round: the bridge was
        // an unchecked (string) cast).
        $form = StrictBindingProbeForm::fromArray(['title' => ['not', 'a', 'string']]);
        $this->assertNull($form->name);
        $this->assertSame([], $form->bindingIssues());
    }

    public function test_update_semantics_resolver(): void
    {
        $this->assertSame(['mode' => 'patch', 'declared' => false], UpdateSemantics::fromManifest([]));
        $this->assertSame(['mode' => 'replace', 'declared' => true], UpdateSemantics::fromManifest(['update' => 'replace']));
        $this->assertSame(['mode' => 'patch', 'declared' => true], UpdateSemantics::fromManifest(['update' => 'patch']));
        // Unrecognised declarations resolve to the framework
        // default AND report as undeclared — a typo is not
        // dressed as a declaration (goldfish round: the first
        // version of this test pinned declared: true here,
        // contradicting the resolver's own contract).
        $this->assertSame(['mode' => 'patch', 'declared' => false], UpdateSemantics::fromManifest(['update' => 'banana']));
        $this->assertSame(['mode' => 'patch', 'declared' => false], UpdateSemantics::fromManifest(['update' => ['replace']]));
        $this->assertSame(['mode' => 'patch', 'declared' => false], UpdateSemantics::fromManifest(['update' => '']));
    }
}

final class StrictBindingProbeForm extends BaseFormBusinessObject
{
    public ?string $name = null;

    public ?int $quantity = null;

    public ?float $price = null;

    public bool $active = false;

    public array $tags = [];
}

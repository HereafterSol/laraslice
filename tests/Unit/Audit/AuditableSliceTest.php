<?php

namespace LaraSlice\Tests\Unit\Audit;

use LaraSlice\Core\Audit\Traits\AuditableSlice;
use PHPUnit\Framework\TestCase;

class DummyAuditedModel
{
    use AuditableSlice;

    public string $auditSlice = 'inventory';

    public array $auditExclude = ['internal_notes'];

    public array $hidden = ['password_hash'];

    public function getKey(): int
    {
        return 55;
    }

    public function getTable(): string
    {
        return 'dummy_items';
    }

    public function getHidden(): array
    {
        return $this->hidden;
    }
}

class DummySliceNamespaceModel
{
    use AuditableSlice;

    public function getKey(): int
    {
        return 99;
    }

    public function getTable(): string
    {
        return 'customers';
    }
}

class AuditableSliceTest extends TestCase
{
    public function test_get_audit_slice_from_property(): void
    {
        $model = new DummyAuditedModel;
        $this->assertSame('inventory', $model->getAuditSlice());
    }

    public function test_get_audit_slice_fallback_to_table(): void
    {
        $model = new DummySliceNamespaceModel;
        $this->assertSame('customers', $model->getAuditSlice());
    }

    public function test_sensitive_attributes_are_excluded(): void
    {
        $model = new DummyAuditedModel;

        // Defaults
        $this->assertTrue($model->isAuditExcluded('password'));
        $this->assertTrue($model->isAuditExcluded('remember_token'));
        $this->assertTrue($model->isAuditExcluded('api_token'));
        $this->assertTrue($model->isAuditExcluded('secret'));
        $this->assertTrue($model->isAuditExcluded('updated_at'));

        // Custom excluded
        $this->assertTrue($model->isAuditExcluded('internal_notes'));

        // Hidden attributes
        $this->assertTrue($model->isAuditExcluded('password_hash'));

        // Allowed attributes
        $this->assertFalse($model->isAuditExcluded('title'));
        $this->assertFalse($model->isAuditExcluded('price'));
        $this->assertFalse($model->isAuditExcluded('sku'));
    }

    public function test_filter_audit_attributes(): void
    {
        $model = new DummyAuditedModel;

        $attributes = [
            'id' => 55,
            'title' => 'Vintage Leather Jacket',
            'price' => 149.99,
            'password' => 'supersecret',
            'remember_token' => 'token123',
            'internal_notes' => 'Supplier discount code 20%',
            'password_hash' => '$2y$10$...',
        ];

        $filtered = $model->filterAuditAttributes($attributes);

        $this->assertArrayHasKey('id', $filtered);
        $this->assertArrayHasKey('title', $filtered);
        $this->assertArrayHasKey('price', $filtered);

        $this->assertArrayNotHasKey('password', $filtered);
        $this->assertArrayNotHasKey('remember_token', $filtered);
        $this->assertArrayNotHasKey('internal_notes', $filtered);
        $this->assertArrayNotHasKey('password_hash', $filtered);
    }

    public function test_without_auditing_disables_auditing_temporarily(): void
    {
        $model = new DummyAuditedModel;
        $this->assertTrue($model->shouldAudit('updated'));

        $executed = false;
        $model->withoutAuditing(function ($m) use (&$executed) {
            $executed = true;
            $this->assertFalse($m->shouldAudit('updated'));
        });

        $this->assertTrue($executed);
        $this->assertTrue($model->shouldAudit('updated'));
    }
}

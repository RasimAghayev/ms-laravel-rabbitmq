<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\Product
 *
 * @property int $id
 * @property string $title
 * @property string $image
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder|Product newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Product newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Product query()
 * @method static \Illuminate\Database\Eloquent\Builder|Product whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Product whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Product whereImage($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Product whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Product whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class Product extends Model
{
    use HasFactory;
    protected $guarded=[];

    /**
     * main's `id` is NOT auto-increment (see the migration: it's a plain
     * `unsignedBigInteger('id')->primary()`) - every id arrives from
     * admin via the replication payload, main never generates one.
     *
     * Without this, Eloquent's default assumption of an auto-increment
     * key means `Product::create()` calls `lastInsertId()` after the
     * INSERT and overwrites the in-memory model's `id` attribute with
     * whatever that returns - 0, since this column was never populated
     * by an AUTO_INCREMENT. The row itself is inserted correctly (the id
     * was in the INSERT's VALUES clause), but the *model instance*'s id
     * is silently corrupted to 0, so any later `->save()`/`->update()`
     * on that same instance issues `UPDATE ... WHERE id = 0` - a no-op
     * that `save()` still reports as successful. `Product::upsert()`
     * (the synchronizer's own write path) and a fresh `Product::find()`/
     * `::where()->first()` are unaffected - this only corrupts the one
     * in-memory instance returned by `create()` itself. Caught by
     * `tests/Unit/Services/ProductFingerprintTest::test_changing_updated_at_changes_the_checksum`,
     * which does exactly that create-then-save-the-same-instance pattern.
     */
    public $incrementing = false;
}

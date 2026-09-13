@php
    use App\Enums\InventoryMovementType;
    use App\Support\Money;
    use App\Support\Quantity;

    $low = $product->is_active && $product->isLowStock();
    $stock = Quantity::trim($product->current_stock);

    // Each movement type gets a small semantic treatment: what it did to stock, and the words the
    // activity row uses. The enum stays the source of truth; this only decides how it reads.
    $activityStyles = [
        InventoryMovementType::Initial->value => ['tone' => 'neutral', 'label' => 'Opening stock'],
        InventoryMovementType::Restock->value => ['tone' => 'positive', 'label' => 'Stock added'],
        InventoryMovementType::Purchase->value => ['tone' => 'positive', 'label' => 'Purchase received'],
        InventoryMovementType::SaleReturn->value => ['tone' => 'positive', 'label' => 'Returned to stock'],
        InventoryMovementType::SaleVoid->value => ['tone' => 'positive', 'label' => 'Sale voided'],
        InventoryMovementType::Sale->value => ['tone' => 'sale', 'label' => 'Sold'],
        InventoryMovementType::Damage->value => ['tone' => 'negative', 'label' => 'Stock removed'],
        InventoryMovementType::Loss->value => ['tone' => 'negative', 'label' => 'Stock removed'],
        InventoryMovementType::Adjustment->value => ['tone' => 'neutral', 'label' => 'Stock adjusted'],
        InventoryMovementType::Correction->value => ['tone' => 'warning', 'label' => 'Correction'],
    ];
@endphp
<x-app-layout :title="$product->name">
    <x-validation-errors />

    <div class="ui-detail-sheet">
        <div class="ui-form-sheet-head">
            <nav class="ui-form-crumbs" aria-label="Breadcrumb">
                <a href="{{ route('inventory.index') }}">Inventory</a>
                <span aria-hidden="true">&rsaquo;</span>
                <strong aria-current="page">{{ $product->name }}</strong>
            </nav>
            <a href="{{ route('inventory.index') }}" class="ui-form-dismiss"
               aria-label="Close and return to inventory" data-tooltip="Close">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true" focusable="false"><path d="M12 4L4 12" stroke="currentColor" stroke-width="1.33333" stroke-linecap="round"/><path d="M4 4L12 12" stroke="currentColor" stroke-width="1.33333" stroke-linecap="round"/></svg>
            </a>
        </div>

        {{-- Header: identity on the left, the two permitted actions on the right --}}
        <div class="ui-detail-head">
            <div class="ui-detail-identity">
                <x-entity-image :url="$product->image_path ? route('inventory.products.image', $product) : null"
                    :label="$product->name" size="h-14 w-14" rounded="rounded-xl" />
                <div class="ui-detail-titles">
                    <h2>{{ $product->name }}</h2>
                    <p class="ui-detail-meta">
                        <span class="ui-detail-sku">{{ $product->sku }}</span>
                        <span>{{ $product->category->name }}</span>
                        @if(! $product->is_active)
                            <span class="ui-stock-state is-archived">Archived · {{ $stock }} {{ $product->unit->value }}</span>
                        @elseif($low)
                            <span class="ui-stock-state is-low">{{ $stock }} in stock · Low stock</span>
                        @else
                            <span class="ui-stock-state is-healthy">{{ $stock }} in stock</span>
                        @endif
                    </p>
                </div>
            </div>

            <div class="ui-detail-actions">
                @can('adjustStock', $product)
                    <a href="#adjust-stock" class="ui-button" data-tooltip="Adjust stock">
                        <svg width="15" height="15" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M2.5 4.5h11M2.5 8h11M2.5 11.5h11" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/><circle cx="6" cy="4.5" r="1.6" fill="currentColor"/><circle cx="10.5" cy="11.5" r="1.6" fill="currentColor"/></svg>
                        Adjust
                    </a>
                @endcan
                @can('update', $product)
                    <a href="{{ route('inventory.products.edit', $product) }}" class="ui-button ui-button-primary"
                       data-edit-product="{{ $product->id }}" data-tooltip="Edit product">
                        <svg width="15" height="15" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M11.3 2.7a1.4 1.4 0 0 1 2 2L5.6 12.4l-2.6.6.6-2.6 7.7-7.7Z" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        Edit
                    </a>
                @endcan
            </div>
        </div>

        {{-- Metric strip. Cost price appears only where ProductPolicy::viewCost allows it. --}}
        <div class="ui-metric-strip">
            <div class="ui-metric-cell">
                <small>Selling price</small>
                <strong>₦{{ Money::format($product->selling_price) }}</strong>
            </div>
            @if($costPrice !== null)
                <div class="ui-metric-cell">
                    <small>Cost price</small>
                    <strong>₦{{ Money::format($costPrice) }}</strong>
                </div>
            @endif
            <div class="ui-metric-cell">
                <small>Reorder level</small>
                <strong @class(['is-low' => $low])>{{ Quantity::trim($product->reorder_level) }}</strong>
            </div>
            <div class="ui-metric-cell">
                <small>Units sold ({{ $soldWindowDays }}d)</small>
                <strong>{{ $unitsSold }}</strong>
            </div>
        </div>

        @if($product->description)
            <div class="ui-detail-description">
                <small>Description</small>
                <p>{{ $product->description }}</p>
            </div>
        @endif

        {{-- Recent activity, read straight from the immutable movement ledger --}}
        @if($canViewMovements)
            <div class="ui-activity">
                <div class="ui-activity-head">
                    <h3>Recent activity</h3>
                    <a href="{{ route('inventory.products.movements', $product) }}">View all</a>
                </div>
                <ul class="ui-activity-list">
                    @forelse($recentMovements as $movement)
                        @php
                            $style = $activityStyles[$movement->type->value] ?? ['tone' => 'neutral', 'label' => ucfirst(str_replace('_', ' ', $movement->type->value))];
                            $change = Quantity::trim($movement->quantity_change);
                            $rises = bccomp((string) $movement->quantity_change, '0', 3) > 0;
                            $falls = bccomp((string) $movement->quantity_change, '0', 3) < 0;
                        @endphp
                        <li class="ui-activity-row">
                            <span class="ui-activity-icon" data-tone="{{ $style['tone'] }}" aria-hidden="true">
                                @if($style['tone'] === 'sale')
                                    <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="M2 2h2l1.7 8.3a1 1 0 0 0 1 .8h5.9a1 1 0 0 0 1-.8L14.5 5H5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/><circle cx="6.5" cy="13.5" r="1" fill="currentColor"/><circle cx="12" cy="13.5" r="1" fill="currentColor"/></svg>
                                @elseif($rises)
                                    <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="M8 3.5v9M3.5 8h9" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                                @elseif($falls)
                                    <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="M3.5 8h9" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                                @else
                                    <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="4.5" stroke="currentColor" stroke-width="1.4"/></svg>
                                @endif
                            </span>
                            <span class="ui-activity-body">
                                <b>{{ $style['label'] }}@if($change !== '0') · {{ $rises ? '+' : '' }}{{ $change }}@endif</b>
                                @if($movement->reason)<span>{{ $movement->reason }}</span>@endif
                            </span>
                            <span class="ui-activity-when">
                                <time datetime="{{ $movement->created_at->toIso8601String() }}">{{ $movement->created_at->format('j M') }}</time>
                                <span>{{ $movement->performer?->name ?? 'System' }}</span>
                            </span>
                        </li>
                    @empty
                        <li class="ui-activity-empty">No stock movements yet.</li>
                    @endforelse
                </ul>
            </div>
        @endif
    </div>

    {{-- Product photo and stock adjustment keep their existing, already-authorized flows --}}
    @can('update', $product)
        <div class="ui-detail-columns">
            <section class="ui-detail-panel">
                <h3>Product photo</h3>
                <p class="ui-detail-hint">JPG, PNG or WebP · up to 2 MB · maximum 4000×4000. Uploading a new photo replaces the current one.</p>
                <form method="POST" action="{{ route('inventory.products.image.store', $product) }}" enctype="multipart/form-data" class="mt-4 space-y-3">
                    @csrf
                    <input type="file" aria-label="Product photo" name="image" accept="image/jpeg,image/png,image/webp" required>
                    @error('image')<p class="ui-field-error">{{ $message }}</p>@enderror
                    <button class="ui-button ui-button-primary">{{ $product->image_path ? 'Replace photo' : 'Upload photo' }}</button>
                </form>
                @if($product->image_path)
                    <div class="mt-4">
                        <x-confirm-action :action="route('inventory.products.image.destroy', $product)" label="Remove photo"
                            message="Remove this product photo? The product itself is unchanged." destructive method="DELETE" />
                    </div>
                @endif
            </section>

            <section class="ui-detail-panel" id="adjust-stock">
                <h3>Adjust stock</h3>
                @can('adjustStock', $product)
                    <p class="ui-detail-hint">Every adjustment is recorded as its own immutable movement.</p>
                    <form method="POST" action="{{ route('inventory.products.adjust', $product) }}" class="mt-4 space-y-3">
                        @csrf
                        <select aria-label="Type" name="type">
                            <option value="restock">Restock</option>
                            <option value="adjustment">Adjustment</option>
                            <option value="damage">Damage</option>
                            <option value="loss">Loss</option>
                            <option value="correction">Correction</option>
                        </select>
                        <select aria-label="Operation" name="operation">
                            <option value="increase">Increase by</option>
                            <option value="decrease">Decrease by</option>
                            <option value="set">Set exact quantity</option>
                        </select>
                        <input aria-label="Quantity" name="quantity" inputmode="decimal" placeholder="Quantity" required>
                        <textarea aria-label="Reason" name="reason" rows="2" placeholder="Reason"></textarea>
                        <button class="ui-button ui-button-primary">Apply adjustment</button>
                    </form>
                @else
                    <p class="ui-detail-hint">You do not have permission to adjust stock for this product.</p>
                @endcan
            </section>
        </div>
    @endcan

    {{-- Lifecycle actions use the same dialog the table and the Edit modal use, so archiving a
         product reads identically wherever it is done. Authorization is unchanged: each @can
         mirrors the policy the route itself enforces. --}}
    @canany(['archive', 'reactivate'], $product)
        <div class="ui-detail-lifecycle">
            <div>
                <strong>Product lifecycle</strong>
                <p>
                    @if($product->is_active)
                        Archiving keeps every sale, movement and record intact and only stops new operational use.
                    @else
                        This product is archived. Its history is intact and it can be restored at any time.
                    @endif
                </p>
            </div>
            <span class="ui-detail-lifecycle-actions">
                @can('archive', $product)
                    <x-lifecycle-confirm :action="route('inventory.products.destroy', $product)" method="DELETE"
                        label="Archive product" title="Archive product?"
                        message="This product will no longer be available for new sales or purchases. Existing sales, inventory movements and historical records will remain available." />
                @endcan
                @can('reactivate', $product)
                    <x-lifecycle-confirm :action="route('inventory.products.reactivate', $product)" tone="primary"
                        label="Reactivate product" title="Reactivate product?"
                        message="This product will become available again for new sales, purchases and other permitted inventory operations." />
                @endcan
            </span>
        </div>
    @endcanany

    @can('update', $product)
        @include('inventory.products._edit-modal')
    @endcan
</x-app-layout>

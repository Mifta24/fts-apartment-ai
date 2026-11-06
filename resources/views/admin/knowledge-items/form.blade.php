@php $isEdit = $item->exists; @endphp

<x-admin-layout :title="$isEdit ? 'Edit knowledge item' : 'Add knowledge item'">
    <form method="POST" action="{{ $isEdit ? route('admin.knowledge-items.update', $item) : route('admin.knowledge-items.store') }}" class="space-y-6">
        @csrf
        @if ($isEdit) @method('PUT') @endif

        <section class="rounded-xl border border-stone-200 bg-white p-5">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-stone-700">Category</label>
                    <select name="category" class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                        @foreach ($categories as $category)
                            <option value="{{ $category }}" @selected(old('category', $item->category) === $category)>{{ ucfirst($category) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-stone-700">Sort order</label>
                    <input type="number" name="sort_order" value="{{ old('sort_order', $item->sort_order) }}" class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                </div>
                <div class="col-span-2">
                    <label class="block text-sm font-medium text-stone-700">Tags (comma-separated, helps the AI match visitor questions)</label>
                    <input type="text" name="tags" value="{{ old('tags', implode(', ', $item->tags ?? [])) }}" placeholder="deposit, parking, move-in"
                        class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                </div>
                <div class="col-span-2">
                    <label class="block text-sm font-medium text-stone-700">Image URL (shown on the facilities cards)</label>
                    <input type="url" name="image_url" value="{{ old('image_url', $item->image_url) }}" placeholder="https://…"
                        class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                </div>
                <label class="col-span-2 flex items-center gap-2 text-sm text-stone-700">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $item->is_active)) class="rounded border-stone-300">
                    Active (the AI can use this entry)
                </label>
            </div>
        </section>

        <section class="rounded-xl border border-stone-200 bg-white p-5">
            <h2 class="text-sm font-semibold text-stone-900">Indonesian (default)</h2>
            <div class="mt-4 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-stone-700">Title</label>
                    <input type="text" name="title" value="{{ old('title', $item->title) }}" required class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-stone-700">Body</label>
                    <textarea name="body" rows="4" required class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">{{ old('body', $item->body) }}</textarea>
                </div>
            </div>
        </section>

        <section class="rounded-xl border border-stone-200 bg-white p-5">
            <h2 class="text-sm font-semibold text-stone-900">English</h2>
            <div class="mt-4 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-stone-700">Title</label>
                    <input type="text" name="translations[en][title]" value="{{ old('translations.en.title', $item->translations['en']['title'] ?? '') }}" class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-stone-700">Body</label>
                    <textarea name="translations[en][body]" rows="4" class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">{{ old('translations.en.body', $item->translations['en']['body'] ?? '') }}</textarea>
                </div>
            </div>
        </section>

        <section class="rounded-xl border border-stone-200 bg-white p-5">
            <h2 class="text-sm font-semibold text-stone-900">Japanese</h2>
            <div class="mt-4 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-stone-700">Title</label>
                    <input type="text" name="translations[ja][title]" value="{{ old('translations.ja.title', $item->translations['ja']['title'] ?? '') }}" class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-stone-700">Body</label>
                    <textarea name="translations[ja][body]" rows="4" class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">{{ old('translations.ja.body', $item->translations['ja']['body'] ?? '') }}</textarea>
                </div>
            </div>
        </section>

        <div class="flex justify-end gap-3">
            <a href="{{ route('admin.knowledge-items.index') }}" class="rounded-lg border border-stone-300 px-4 py-2 text-sm text-stone-600 hover:bg-stone-50">Cancel</a>
            <button type="submit" class="rounded-lg bg-ink px-4 py-2 text-sm font-medium text-white hover:bg-signal hover:text-signal-ink">Save entry</button>
        </div>
    </form>
</x-admin-layout>

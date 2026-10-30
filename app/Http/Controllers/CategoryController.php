<?php

namespace App\Http\Controllers;

use App\Http\Requests\CategoryRequest;
use App\Models\Category;
use App\Services\MasterDataDeletion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CategoryController extends Controller
{
    public function index(Request $request): View
    {
        $search = $request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? '';
        $categories = Category::withCount('products')->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))->orderBy('name')->paginate(15)->withQueryString();

        return view('categories.index', compact('categories', 'search'));
    }

    public function create(): View
    {
        return view('categories.form', ['category' => new Category]);
    }

    public function store(CategoryRequest $request): RedirectResponse
    {
        Category::create($request->validated());

        return redirect()->route($request->user()->can('categories.view') ? 'categories.index' : 'categories.create')->with('status', __('app.saved'));
    }

    public function edit(Category $category): View
    {
        return view('categories.form', compact('category'));
    }

    public function update(CategoryRequest $request, Category $category): RedirectResponse
    {
        $category->update($request->validated());

        return redirect()->route($request->user()->can('categories.view') ? 'categories.index' : 'categories.create')->with('status', __('app.saved'));
    }

    public function destroy(Request $request, Category $category, MasterDataDeletion $deletion): RedirectResponse
    {
        $deletion->delete($category);

        return redirect()->route($request->user()->can('categories.view') ? 'categories.index' : 'categories.create')->with('status', __('catalog.deleted'));
    }
}

<?php

namespace App\Http\Requests\Authz;

class UpdateMenuCategoryRequest extends StoreMenuCategoryRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('menu.update');
    }
}

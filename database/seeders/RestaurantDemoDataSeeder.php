<?php

namespace Database\Seeders;

use App\Models\DiningTable;
use App\Models\InventoryItem;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class RestaurantDemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $adminRole = Role::where('name', 'General Admin')->first();
        $waiterRole = Role::where('name', 'Waiter')->first();
        $cashierRole = Role::where('name', 'Cashier')->first();

        $admin = User::firstOrCreate(
            ['email' => 'admin@restaurant.local'],
            ['name' => 'System Admin', 'password' => Hash::make('password'), 'is_active' => true]
        );
        if ($adminRole) $admin->syncRoles([$adminRole]);

        $waiter = User::firstOrCreate(
            ['email' => 'waiter@restaurant.local'],
            ['name' => 'Default Waiter', 'password' => Hash::make('password'), 'is_active' => true]
        );
        if ($waiterRole) $waiter->syncRoles([$waiterRole]);

        $cashier = User::firstOrCreate(
            ['email' => 'cashier@restaurant.local'],
            ['name' => 'Default Cashier', 'password' => Hash::make('password'), 'is_active' => true]
        );
        if ($cashierRole) $cashier->syncRoles([$cashierRole]);

        foreach ([['T1', 4], ['T2', 4], ['T3', 2], ['T4', 6]] as [$number, $cap]) {
            DiningTable::firstOrCreate(['table_number' => $number], ['capacity' => $cap, 'status' => 'available', 'is_active' => true]);
        }

        $food = MenuCategory::firstOrCreate(['name' => 'Food'], ['is_active' => true]);
        $drink = MenuCategory::firstOrCreate(['name' => 'Drinks'], ['is_active' => true]);

        $bread = InventoryItem::firstOrCreate(['name' => 'Bread'], ['sku' => 'INV-BREAD', 'unit' => 'pcs', 'quantity' => 100, 'reorder_level' => 20, 'unit_cost' => 5]);
        $beef = InventoryItem::firstOrCreate(['name' => 'Beef'], ['sku' => 'INV-BEEF', 'unit' => 'kg', 'quantity' => 50, 'reorder_level' => 10, 'unit_cost' => 600]);
        $softDrink = InventoryItem::firstOrCreate(['name' => 'Soft Drink Syrup'], ['sku' => 'INV-SYRUP', 'unit' => 'ltr', 'quantity' => 20, 'reorder_level' => 5, 'unit_cost' => 120]);

        $burger = MenuItem::firstOrCreate(['name' => 'Burger'], ['category_id' => $food->id, 'type' => 'food', 'price' => 180, 'is_active' => true, 'is_available' => true]);
        $cola = MenuItem::firstOrCreate(['name' => 'Cola'], ['category_id' => $drink->id, 'type' => 'drink', 'price' => 60, 'is_active' => true, 'is_available' => true]);

        $burgerRecipe = Recipe::firstOrCreate(['menu_item_id' => $burger->id]);
        RecipeItem::firstOrCreate(['recipe_id' => $burgerRecipe->id, 'inventory_item_id' => $bread->id], ['quantity' => 2]);
        RecipeItem::firstOrCreate(['recipe_id' => $burgerRecipe->id, 'inventory_item_id' => $beef->id], ['quantity' => 0.2]);

        $colaRecipe = Recipe::firstOrCreate(['menu_item_id' => $cola->id]);
        RecipeItem::firstOrCreate(['recipe_id' => $colaRecipe->id, 'inventory_item_id' => $softDrink->id], ['quantity' => 0.05]);

        Supplier::firstOrCreate(['name' => 'Default Supplier'], ['phone' => '0000000000', 'email' => 'supplier@restaurant.local', 'is_active' => true]);
    }
}

<?php

namespace App\Support;

final class RoleNames
{
    public const GENERAL_ADMIN = 'General Admin';
    public const MANAGER = 'Manager';
    public const FOOD_CONTROLLER = 'F&B Controller';
    public const FINANCE = 'Finance';
    public const PURCHASER = 'Purchaser';
    public const STORE_KEEPER = 'Store Keeper';
    public const CASHIER = 'Cashier';
    public const KITCHEN_STAFF = 'Kitchen Staff';
    public const BARMAN = 'Barman';
    public const WAITER = 'Waiter';
    public const CUSTOMER = 'Customer';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::GENERAL_ADMIN,
            self::MANAGER,
            self::FOOD_CONTROLLER,
            self::FINANCE,
            self::PURCHASER,
            self::STORE_KEEPER,
            self::CASHIER,
            self::KITCHEN_STAFF,
            self::BARMAN,
            self::WAITER,
            self::CUSTOMER,
        ];
    }

    /** @return array<string, string> */
    public static function aliases(): array
    {
        return [
            'Admin' => self::GENERAL_ADMIN,
            'General Administrator' => self::GENERAL_ADMIN,
            'Cafeteria Manager' => self::MANAGER,
            'Finance Manager' => self::FINANCE,
            'F & B Controller' => self::FOOD_CONTROLLER,
            'Food Controller' => self::FOOD_CONTROLLER,
            'customer' => self::CUSTOMER,
        ];
    }

    public static function canonical(string $role): ?string
    {
        $role = trim($role);

        foreach (self::all() as $canonical) {
            if (strcasecmp($role, $canonical) === 0) {
                return $canonical;
            }
        }

        foreach (self::aliases() as $alias => $canonical) {
            if (strcasecmp($role, $alias) === 0) {
                return $canonical;
            }
        }

        return null;
    }

    private function __construct()
    {
    }
}

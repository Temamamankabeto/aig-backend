<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DashboardController extends Controller
{

    public function customerDashboard(Request $request)
    {
        return response()->json([
            "success" => true,
            "role" => "customer",
            "message" => "Customer Dashboard",
            "user" => $request->user()
        ]);
    }

    public function waiterDashboard(Request $request)
    {
        Gate::authorize('dashboard.waiter');
        return response()->json([
            "success" => true,
            "role" => "waiter",
            "message" => "Waiter Dashboard",
            "user" => $request->user()
        ]);
    }

    public function cashierDashboard(Request $request)
    {
        Gate::authorize('cashier.dashboard');
        return response()->json([
            "success" => true,
            "role" => "cashier",
            "message" => "Cashier Dashboard",
            "user" => $request->user()
        ]);
    }

    public function barDashboard(Request $request)
    {
        Gate::authorize('bar.dashboard');
        return response()->json([
            "success" => true,
            "role" => "barman",
            "message" => "Bar Dashboard",
            "user" => $request->user()
        ]);
    }

    public function kitchenDashboard(Request $request)
    {
        Gate::authorize('kitchen.dashboard');
        return response()->json([
            "success" => true,
            "role" => "kitchen",
            "message" => "Kitchen Dashboard",
            "user" => $request->user()
        ]);
    }

    public function foodControllerDashboard(Request $request)
    {
        Gate::authorize('food-controller.dashboard');
        return response()->json([
            "success" => true,
            "role" => "food-controller",
            "message" => "Food Controller Dashboard",
            "user" => $request->user()
        ]);
    }

    public function financeDashboard(Request $request)
    {
        Gate::authorize('finance.dashboard');
        return response()->json([
            "success" => true,
            "role" => "finance",
            "message" => "Finance Dashboard",
            "user" => $request->user()
        ]);
    }

    public function managerDashboard(Request $request)
    {
        Gate::authorize('manager.dashboard');
        return response()->json([
            "success" => true,
            "role" => "manager",
            "message" => "Manager Dashboard",
            "user" => $request->user()
        ]);
    }

    public function generalDashboard(Request $request)
    {
        Gate::authorize('general.dashboard');
        return response()->json([
            "success" => true,
            "role" => "general-admin",
            "message" => "General Admin Dashboard",
            "user" => $request->user()
        ]);
    }

}
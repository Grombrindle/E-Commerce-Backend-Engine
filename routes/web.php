<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn() => response()->json([
    'name'    => 'EcommerceAPI',
    'version' => '1.0.0',
    'docs'    => '/api/v1/health',
]));

use App\Models\Product;

Route::get('/view-db', function () {
    $products = Product::with('inventory')->get();
    
    $html = "<h1>قائمة المنتجات في قاعدة البيانات</h1>";
    $html .= "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
    $html .= "<tr><th>ID</th><th>الاسم</th><th>السعر</th><th>المخزون</th></tr>";
    
    foreach ($products as $p) {
        $stock = $p->inventory ? $p->inventory->quantity : 0;
        $html .= "<tr>";
        $html .= "<td>" . $p->id . "</td>";
        $html .= "<td>" . $p->name . "</td>";
        $html .= "<td>" . $p->price . "</td>";
        $html .= "<td>" . $stock . "</td>";
        $html .= "</tr>";
    }
    
    $html .= "</table>";
    return $html;
});

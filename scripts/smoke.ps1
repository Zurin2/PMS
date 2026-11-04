# PMS API smoke tests (PowerShell 5.1 + curl.exe)
# Usage: powershell -ExecutionPolicy Bypass -File scripts\smoke.ps1
# Temporarily swaps the owner password (original hash restored in finally).
$ErrorActionPreference = 'Stop'
$PHP  = 'C:\xampp\php\php.exe'
$ROOT = 'C:\xampp\htdocs\PMS'
$BASE = 'http://localhost/PMS/api'
$JAR  = Join-Path ([System.IO.Path]::GetTempPath()) 'pms_cookies.txt'

$script:pass = 0; $script:fail = 0
function Fix($name, $actual, $expected) {
    $actual = (($actual | Out-String)).Trim()
    if ($actual -eq $expected) { $script:pass++ }
    else { $script:fail++; Write-Host "FAIL $name -> got [$actual], want [$expected]" }
}
$BODYFILE = Join-Path ([System.IO.Path]::GetTempPath()) 'pms_body.json'
function Hit($method, $url, $body) {
    $ca = @('-s','-o','NUL','-w','%{http_code}','-X',$method,'-b',$JAR,'-c',$JAR)
    if ($body -ne $null -and $body -ne '') {
        [IO.File]::WriteAllText($BODYFILE, $body)
        $ca += '-H'; $ca += 'Content-Type: application/json';
        $ca += '--data-binary'; $ca += "@$BODYFILE"
    }
    $code = & curl.exe @ca "$BASE/$url"
    Remove-Item $BODYFILE -ErrorAction SilentlyContinue
    return $code
}
function PostBody($url, $body) {
    [IO.File]::WriteAllText($BODYFILE, $body)
    $s = (& curl.exe -s -b $JAR -c $JAR -X POST -H 'Content-Type: application/json' -H 'Accept: application/json' --data-binary "@$BODYFILE" "$BASE/$url") -join "`n"
    Remove-Item $BODYFILE -ErrorAction SilentlyContinue
    "$s"
}
function Body($url) {
    $s = (& curl.exe -s -b $JAR -c $JAR "$BASE/$url") -join "`n"
    "$s"
}
function Extract($json, $key) {
    if ($json -match ('"' + $key + '":(\d+)')) { $Matches[1] } else { '' }
}

& $PHP "$ROOT\scripts\_pw.php" set
try {
    $TS = Get-Date -Format 'HHmmss'   # unique-per-run identifiers to survive reruns
    Fix 'wrong creds 401'  (Hit 'POST' 'auth.php' '{"username":"owner","password":"wrong"}') '401'
    Fix 'login 200'        (Hit 'POST' 'auth.php' '{"username":"owner","password":"pms-test-2026"}') '200'
    Fix 'me 200'           (Hit 'GET'  'auth.php?action=me') '200'
    Fix 'unauth items 401' (& curl.exe -s -o NUL -w '%{http_code}' "$BASE/items.php") '401'

    # ---- items ----
    $r = PostBody 'items.php' ('{"category_id":1,"item_code":"T-' + $TS + '","name":"Test Ballpen ' + $TS + '","unit":"pc","unit_price":12.50,"low_stock_level":5}')
    $IT = Extract $r 'item_id'
    Fix 'item create 201'  (Hit 'POST' 'items.php' ('{"category_id":1,"item_code":"T-' + $TS + '","name":"Dup Pen","unit":"pc","unit_price":1,"low_stock_level":0}')) '409'
    Fix 'item create id != empty' ([bool]($IT)) 'True'
    Fix 'item price update 200' (Hit 'PUT' "items.php?id=$IT" '{"unit_price":13.00}') '200'
    Fix 'price history logged' ([bool](((Body "items.php?id=$IT") -match 'effective_at'))) 'True'

    # ---- stock in ----
    Fix 'stock in 201' (Hit 'POST' 'stock_in.php' ('{"item_id":' + $IT + ',"quantity":20,"date_received":"2026-11-04","remarks":"test receipt"}')) '201'
    Fix 'stock card soh 20' ([bool](((Body "stock_card.php?item_id=$IT") -match '"balance":20'))) 'True'

    # ---- customers (test school) ----
    Fix 'customer create 201' (Hit 'POST' 'customers.php' ('{"name":"Test School ' + $TS + '","address":"Test St","customer_type":"SCHOOL","school_level":"ELEMENTARY","contacts":[{"contact_type":"MOBILE","contact_value":"09170000001"}]}')) '201'

    # ---- deliveries ----
    Fix 'insufficient stock 409' (Hit 'POST' 'deliveries.php' ('{"customer_id":1,"delivery_date":"2026-11-04","order_type":"DIRECT","po_number":"PO-TEST","po_date":"2026-11-04","items":[{"item_id":' + $IT + ',"quantity":999,"unit_price":12.50}]}')) '409'
    $r = PostBody 'deliveries.php' ('{"customer_id":1,"delivery_date":"2026-11-04","order_type":"DIRECT","po_number":"PO-TEST","po_date":"2026-11-04","items":[{"item_id":' + $IT + ',"quantity":3,"unit_price":12.50}]}')
    $DEL = Extract $r 'delivery_id'
    Fix 'delivery created' ($DEL -ne '') 'True'
    Fix 'stock card soh 17' ([bool](((Body "stock_card.php?item_id=$IT") -match '"balance":17'))) 'True'

    # ---- payments ----
    Fix 'overpay 409' (Hit 'POST' 'payments.php' ('{"delivery_id":' + $DEL + ',"amount":999,"payment_date":"2026-11-04","method":"CASH"}')) '409'
    Fix 'payment 201' (Hit 'POST' 'payments.php' ('{"delivery_id":' + $DEL + ',"amount":20,"payment_date":"2026-11-04","method":"CASH"}')) '201'
    Fix 'cancel paid 409' (Hit 'PUT' "deliveries.php?id=$DEL" '{"status":"CANCELLED"}') '409'

    # ---- adjustments ----
    Fix 'adjustment 201' (Hit 'POST' 'adjustments.php' ('{"item_id":' + $IT + ',"counted_quantity":15,"adjust_date":"2026-11-04","reason":"count test"}')) '201'
    Fix 'stock card soh 15' ([bool](((Body "stock_card.php?item_id=$IT") -match '"balance":15'))) 'True'

    # ---- analytics & receipt ----
    Fix 'dashboard 200'       (Hit 'GET' 'dashboard.php') '200'
    Fix 'reports matrix 200'  (Hit 'GET' 'reports.php?report=matrix&from=2026-01-01&to=2026-12-31') '200'
    Fix 'reports summary 200' (Hit 'GET' 'reports.php?report=summary&from=2026-01-01&to=2026-12-31') '200'
    Fix 'receipt html 200'    (& curl.exe -s -o NUL -w '%{http_code}' -b $JAR -c $JAR "$BASE/receipt.php?id=$DEL") '200'

    Fix 'category delete 409' (Hit 'DELETE' 'categories.php?id=1') '409'

    Write-Host ""
    Write-Host "RESULT: $script:pass passed / $script:fail failed"
}
finally {
    & $PHP "$ROOT\scripts\_pw.php" restore
    Remove-Item $JAR -ErrorAction SilentlyContinue
}

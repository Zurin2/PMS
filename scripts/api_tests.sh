#!/usr/bin/env bash
# =============================================================
# PMS Inventory Tracking & Delivery Monitoring System
# File: scripts/api_tests.sh
# Description: cURL smoke tests for all API endpoints
# Usage: bash scripts/api_tests.sh
# Requires: curl, running XAMPP with pms_inventory database loaded
# =============================================================

BASE="http://localhost/pms/api"
COOKIE_JAR="jar.txt"

echo "============================================="
echo "  PMS API Smoke Tests"
echo "============================================="

# 1. Test 401 without session
echo ""
echo "[1] GET items without login -> expect 401"
curl -si "$BASE/items.php" | head -1

# 2. Login
echo ""
echo "[2] POST login -> expect 200"
curl -sc $COOKIE_JAR -X POST \
  -H "Content-Type: application/json" \
  -d '{"username": "owner", "password": "changeme"}' \
  "$BASE/auth.php?action=login"

# 3. Auth check
echo ""
echo "[3] GET me -> expect 200 with user_id"
curl -sb $COOKIE_JAR "$BASE/auth.php?action=me"

# 4. List items (empty)
echo ""
echo "[4] GET items list -> expect 200 JSON array"
curl -sb $COOKIE_JAR "$BASE/items.php"

# 5. Create item
echo ""
echo "[5] POST item -> expect 201 with item_id"
curl -sb $COOKIE_JAR -X POST \
  -H "Content-Type: application/json" \
  -d '{"category_id":1,"item_code":"SCH-001","name":"Notebook 80 lvs","unit":"pc","unit_price":18,"low_stock_level":50}' \
  "$BASE/items.php"

# 6. Duplicate item code -> expect 409
echo ""
echo "[6] POST duplicate item_code -> expect 409"
curl -sb $COOKIE_JAR -X POST \
  -H "Content-Type: application/json" \
  -d '{"category_id":1,"item_code":"SCH-001","name":"Other","unit":"pc","unit_price":5}' \
  "$BASE/items.php"

# 7. Update item price
echo ""
echo "[7] PUT item (price change) -> expect 200"
curl -sb $COOKIE_JAR -X PUT \
  -H "Content-Type: application/json" \
  -d '{"category_id":1,"name":"Notebook 80 lvs","unit":"pc","unit_price":19,"low_stock_level":50}' \
  "$BASE/items.php?id=1"

# 8. Get low stock report
echo ""
echo "[8] GET reports low_stock -> expect 200"
curl -sb $COOKIE_JAR "$BASE/reports.php?type=low_stock"

# 9. Logout
echo ""
echo "[9] GET logout -> expect 200"
curl -sb $COOKIE_JAR "$BASE/auth.php?action=logout"

# Cleanup
rm -f $COOKIE_JAR
echo ""
echo "============================================="
echo "  Smoke tests complete."
echo "============================================="

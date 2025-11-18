# WishCart Plugin Activation Instructions

## Quick Start Guide

### Step 1: Build Frontend Assets
```bash
cd /path/to/wishcart-plugin
npm install
npm run build
```

This will compile all React components into the `build/` directory.

### Step 2: Activate Plugin
1. Go to WordPress Admin → Plugins
2. Find "WishCart - Wishlist for FluentCart"
3. Click "Activate"

**What happens on activation:**
- Creates 7 database tables (if not exist)
- Registers rewrite rules for `/wishlist/share/{token}`
- Schedules cron jobs
- Creates wishlist page
- Flushes rewrite rules

### Step 3: Flush Rewrite Rules (Important!)
After activation, go to:
- **Settings → Permalinks**
- Click "Save Changes" (don't change anything)
- This ensures share URLs work: `/wishlist/share/abc123`

### Step 4: Test Share Feature

#### Test 1: Create a Wishlist
1. Go to `/wishlist/` page
2. Log in if not already
3. Add some products to wishlist
4. Verify products appear

#### Test 2: Share a Wishlist
1. Open your wishlist
2. Change privacy from "Private" to "Shared" or "Public"
3. Click "Share" button
4. Modal should open with share link
5. Copy the link

#### Test 3: View Shared Wishlist
1. Open link in incognito/private browser
2. You should see the shared wishlist view
3. Verify all products display
4. Try clicking "Add to Cart"

#### Test 4: Check Database
Go to phpMyAdmin and check these tables:
- `wp_fc_wishlists` - Should have your wishlist
- `wp_fc_wishlist_items` - Should have your products
- `wp_fc_wishlist_shares` - Should have share record
- `wp_fc_wishlist_activities` - Should have logged activities
- `wp_fc_wishlist_analytics` - Should track views

---

## Troubleshooting

### Issue: Share links show 404
**Solution**: Flush rewrite rules
```php
// Add this code to functions.php temporarily
add_action('init', function() {
    flush_rewrite_rules();
});
// Visit your site, then REMOVE the code
```

### Issue: "This wishlist is currently private" error
**Solution**: Change wishlist privacy
1. Open wishlist page
2. Click privacy dropdown
3. Select "Shared" or "Public"
4. Try sharing again

### Issue: No products in shared view
**Solution**: Check wishlist has products
1. Verify products in main wishlist view
2. Check database: `wp_fc_wishlist_items`
3. Ensure products have `status = 'active'`

### Issue: Share modal doesn't open
**Solution**: Check JavaScript console
1. Open browser DevTools (F12)
2. Look for React errors
3. Ensure `build/index.js` is loaded
4. Check `WishCartWishlist` object exists in console

### Issue: Table doesn't exist
**Solution**: Deactivate and reactivate plugin
1. Deactivate WishCart
2. Reactivate WishCart
3. Check database for tables

---

## Database Verification

### Check Tables Created
```sql
SHOW TABLES LIKE 'wp_fc_wishlist%';
```

Should show 7 tables:
1. `wp_fc_wishlists`
2. `wp_fc_wishlist_items`
3. `wp_fc_wishlist_shares`
4. `wp_fc_wishlist_analytics`
5. `wp_fc_wishlist_notifications`
6. `wp_fc_wishlist_activities`
7. `wp_fc_wishlist_guest_users`

### Check Share Data
```sql
SELECT * FROM wp_fc_wishlist_shares WHERE status = 'active';
```

Should show share records with:
- `share_token` (64 characters)
- `wishlist_id`
- `share_type` (link, email, facebook, etc.)
- `click_count`
- `date_created`

### Check Wishlist Privacy
```sql
SELECT id, wishlist_name, privacy_status 
FROM wp_fc_wishlists 
WHERE status = 'active';
```

Privacy values:
- `private` - Not shareable
- `shared` - Shareable via link
- `public` - Public + shareable

---

## API Endpoint Testing

### Test Share View Endpoint (Public)
```bash
curl https://yoursite.com/wp-json/wishcart/v1/share/YOUR_SHARE_TOKEN/view
```

Should return:
```json
{
  "success": true,
  "wishlist": {
    "id": 1,
    "name": "My Wishlist",
    "owner_name": "John Doe",
    "privacy_status": "shared"
  },
  "products": [
    {
      "id": 123,
      "name": "Product Name",
      "price": "29.99",
      "image_url": "...",
      "stock_status": "instock"
    }
  ]
}
```

### Test Share Creation (Authenticated)
```bash
curl -X POST https://yoursite.com/wp-json/wishcart/v1/share/create \
  -H "X-WP-Nonce: YOUR_NONCE" \
  -H "Content-Type: application/json" \
  -d '{
    "wishlist_id": 1,
    "share_type": "link"
  }'
```

Should return:
```json
{
  "success": true,
  "share": {
    "share_id": 1,
    "share_token": "abc123...",
    "wishlist_id": 1
  },
  "share_url": "https://yoursite.com/wishlist/share/abc123..."
}
```

---

## Rewrite Rules Verification

### Check Registered Rules
Add this to a test page:
```php
<?php
global $wp_rewrite;
print_r($wp_rewrite->rules);
?>
```

Look for:
```
'wishlist/share/([a-zA-Z0-9]+)/?$' => 'index.php?wishcart_share_token=$matches[1]'
```

### Check Query Vars
```php
<?php
global $wp;
print_r($wp->public_query_vars);
?>
```

Should include: `wishcart_share_token`

---

## Frontend Verification

### Check Scripts Loaded
View page source and look for:
```html
<script src=".../wishcart/build/index.js"></script>
<script>var WishCartWishlist = {...};</script>
```

### Check React Mount
In browser console:
```javascript
document.getElementById('shared-wishlist-app')
```

Should exist on share pages.

### Check Localized Data
```javascript
console.log(WishCartShared);
```

Should show:
```javascript
{
  apiUrl: "/wp-json/wishcart/v1/",
  shareToken: "abc123...",
  siteUrl: "https://yoursite.com",
  isUserLoggedIn: false
}
```

---

## Performance Checks

### Database Indexes
```sql
SHOW INDEX FROM wp_fc_wishlist_shares;
```

Should have indexes on:
- `PRIMARY` (share_id)
- `share_token` (UNIQUE)
- `wishlist_id`
- `share_type`
- `status`

### Query Performance
```sql
EXPLAIN SELECT * FROM wp_fc_wishlist_shares 
WHERE share_token = 'abc123' AND status = 'active';
```

Should use `share_token_idx` index.

---

## Security Verification

### Test Private Wishlist Blocking
1. Create a private wishlist
2. Generate share link
3. Visit link in incognito
4. Should see error: "This wishlist is private"

### Test Expired Shares
```sql
UPDATE wp_fc_wishlist_shares 
SET date_expires = '2020-01-01 00:00:00' 
WHERE share_id = 1;
```

Visit share link → Should show "expired" error

### Test Invalid Tokens
Visit: `/wishlist/share/invalidtoken123`
Should show 404 or "not found" error

---

## Analytics Verification

### Check Share Tracking
```sql
SELECT 
  ws.share_id,
  ws.share_token,
  ws.share_type,
  ws.click_count,
  ws.date_created,
  ws.last_clicked
FROM wp_fc_wishlist_shares ws
WHERE ws.status = 'active'
ORDER BY ws.click_count DESC;
```

### Check Activity Logs
```sql
SELECT 
  activity_type,
  COUNT(*) as count
FROM wp_fc_wishlist_activities
WHERE activity_type IN ('shared', 'viewed')
GROUP BY activity_type;
```

### Check Analytics Updates
```sql
SELECT 
  product_id,
  share_count,
  click_count
FROM wp_fc_wishlist_analytics
WHERE share_count > 0;
```

---

## Production Deployment

### Pre-Deployment Checklist
- [ ] Run `npm run build` (production build)
- [ ] Test on staging environment
- [ ] Backup database
- [ ] Backup current plugin files
- [ ] Test all share types
- [ ] Test mobile responsive
- [ ] Test different browsers

### Deployment Steps
1. **Upload Plugin**
   ```bash
   rsync -av wishcart/ user@server:/path/to/wp-content/plugins/wishcart/
   ```

2. **Activate on Production**
   - Go to WordPress Admin
   - Activate plugin
   - Flush rewrite rules

3. **Verify Tables Created**
   ```bash
   wp db query "SHOW TABLES LIKE 'wp_fc_wishlist%';"
   ```

4. **Test Share Feature**
   - Create test wishlist
   - Share it
   - Visit link in incognito

### Post-Deployment
1. Monitor error logs
2. Check share creation rate
3. Monitor database growth
4. Test performance
5. Gather user feedback

---

## Support & Debugging

### Enable Debug Mode
In `wp-config.php`:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

Check logs: `wp-content/debug.log`

### Common Log Messages
- `WishCart: Share created for wishlist ID {id}`
- `WishCart: Share view tracked: {token}`
- `WishCart: Activity logged: {type}`

### Get Support
- **Documentation**: SHARE-FEATURE-IMPLEMENTATION.md
- **Issues**: Check browser console + WordPress debug.log
- **Database**: Verify tables and data integrity

---

## Success Indicators

### Everything Works If:
✅ 7 tables exist in database
✅ Rewrite rules registered
✅ Share modal opens
✅ Share links generate
✅ Links save to database
✅ Shared view page loads
✅ Products display correctly
✅ Add to cart works
✅ Click tracking increments
✅ Activities logged
✅ Mobile responsive

### If Not Working:
1. Check WordPress debug.log
2. Check browser console
3. Verify database tables
4. Flush rewrite rules
5. Rebuild frontend assets
6. Check file permissions

---

**Status**: Ready for Production
**Last Updated**: November 18, 2025
**Version**: 1.0.0

For detailed technical documentation, see:
- **SHARE-FEATURE-IMPLEMENTATION.md**
- **FRONTEND-IMPLEMENTATION.md**
- **MVP-README.md**


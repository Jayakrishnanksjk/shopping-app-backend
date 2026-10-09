# Push Notifications (Zomato-style, works when app is closed)

Backend is done. Admin creates a broadcast (title + body + optional image + schedule) and the
server pushes it to all phones via Firebase Cloud Messaging (FCM), plus stores a copy in each
user's in-app inbox (`GET /api/notifications` keeps working as before).

## How it works

- `push_broadcasts` table: title, body, image (`image_id` via Attachments API or raw `image_url`),
  `schedule_type` = `immediate | once | daily | interval`, plus `scheduled_at` / `daily_time` /
  `interval_minutes` / `starts_at` / `ends_at`. Scheduler computes `next_run_at`.
- `device_tokens` table: FCM tokens registered by the app.
- Push goes to the shared FCM topic `all_users` (reaches even logged-out phones) + direct token
  multicast as backup. Dead tokens are auto-deactivated.
- `SendPushBroadcastJob` also bulk-inserts a `broadcast` entry into `notifications` for every user.
- `php artisan broadcasts:dispatch-due` runs every minute via `app/Console/Kernel.php`.

## Backend setup (one time)

1. `composer install` (pulls `kreait/firebase-php`).
2. `php artisan migrate` (creates `push_broadcasts`, `device_tokens`).
3. Permissions: `push_broadcast.index/create/edit/destroy` are seeded for Admin by
   `RoleSeeder` (already added — runs on fresh seeds).
4. Firebase:
   - Create a project at https://console.firebase.google.com, add Android + iOS apps.
   - Project Settings → Service accounts → Generate new private key → save JSON as
     `storage/firebase-service-account.json` (never commit it).
   - `.env`: `FIREBASE_PROJECT_ID=...`, `FIREBASE_CREDENTIALS=storage/firebase-service-account.json`.
   - Without the key, the backend runs in **log mode**: inbox copies still work, push payloads
     are only logged (good for local dev).
5. Scheduler on the server (required for `once/daily/interval`):
   `* * * * * php /path/to/artisan schedule:run >> /dev/null 2>&1`
6. Optional queue worker for faster fan-out: `php artisan queue:work` (default `QUEUE_CONNECTION=sync`
   also works — sends run inline).

## Admin API (your backend "Notifications" tab calls these)

All require Sanctum auth (admin). Base: `/api/push-broadcast`.

- `GET /api/push-broadcast?status=active&schedule_type=daily` — list
- `POST /api/push-broadcast` — create. Examples:
  - Send now: `{"title":"Diwali Sale","body":"50% off today","schedule_type":"immediate"}`
  - One-time: `{"title":"...","body":"...","schedule_type":"once","scheduled_at":"2026-10-10 09:00:00"}`
  - Daily 9am: `{"title":"...","body":"...","schedule_type":"daily","daily_time":"09:00","starts_at":"2026-10-10","ends_at":"2026-12-31"}`
  - Every 6h: `{"title":"...","body":"...","schedule_type":"interval","interval_minutes":360}`
  - With image: add `"image_id":123` (upload first via `POST /api/attachment`) or `"image_url":"https://..."`
  - With tap action: `"redirect_type":"product","redirect_target":"<slug-or-id>"`
- `GET /api/push-broadcast/{id}` · `PUT /api/push-broadcast/{id}` · `DELETE ...`
- `POST /api/push-broadcast/{id}/send-now` — push immediately regardless of schedule
- `POST /api/push-broadcast/{id}/pause` · `POST /api/push-broadcast/{id}/resume`
- `POST /api/push-broadcast/deleteAll` `{"ids":[1,2]}`
- App token endpoints: `POST /api/device-tokens {"token":"...","platform":"android"}` (auth),
  `DELETE /api/device-tokens {"token":"..."}` on logout.

## Mobile app (React Native) — what your app developer must do

1. `npm i @react-native-firebase/app @react-native-firebase/messaging @notifee/react-native`
2. Firebase console: register the Android package name + iOS bundle id, download
   `google-services.json` (into `android/app/`) and `GoogleService-Info.plist` (Xcode).
   iOS: enable Push + Background Modes → remote notifications in Xcode, upload APNs key in console.
3. On every launch (after login AND before login):
   - `await messaging().requestPermission()` (iOS), `await messaging().registerDeviceForRemoteMessages()`
   - `const token = await messaging().getToken()` → `POST /api/device-tokens`
   - `messaging().onTokenRefresh(t => POST /api/device-tokens)`; on logout `DELETE /api/device-tokens`
   - `await messaging().subscribeToTopic('all_users')` ← this is what makes broadcasts reach
     logged-out phones too. Do it for guests as well.
4. Display while app is in foreground: `messaging().onMessage(m => notifee.displayNotification(...))`.
   Background/killed: `messaging().setBackgroundMessageHandler(async m => notifee.displayNotification(...))`.
   Android: create a `default` channel; big-picture style shows the image automatically when the
   push contains one. Tapping should read `data.redirect_type/redirect_target` and deep-link.

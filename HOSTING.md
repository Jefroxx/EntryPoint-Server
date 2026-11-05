# Hosting EntryPoint for free

This puts the three parts of the system on three free services. Nothing needs your PC to stay on.

| Part | Where | What it is |
|---|---|---|
| Front end (`EntryPoint-Client`) | **Cloudflare Pages** | The website people open |
| API (`EntryPoint-Server`) | **Render** (Docker web service) | Laravel, built from the `Dockerfile` here |
| Database | **Aiven** (free MySQL) | Where all the records live |
| Pictures | **Cloudflare R2** | Book page photos and reward photos |
| Email | **Brevo** | The verification email |
| Keep-awake and schedule | **cron-job.org** | Calls the API every 10 minutes |

Do the steps in this order. Write down each value as you get it; the last step uses all of them.

---

## 1. Database (Aiven)

1. Create an account at aiven.io (no card needed) and create a **MySQL** service on the **Free** plan, in an Asia region.
2. When it is running, open its overview and note **Host, Port, User, Password, Database name** (`defaultdb`).
3. Download the **CA certificate** (`ca.pem`). Convert it to one line of text for Render:
   - Windows PowerShell: `[Convert]::ToBase64String([IO.File]::ReadAllBytes("ca.pem"))`
   - Keep the result for `DB_SSL_CA_BASE64`.

## 2. Pictures (Cloudflare R2)

1. In the Cloudflare dashboard open **R2 object storage** and create a bucket named `entrypoint` (it may ask for a card; the free allowance is enough).
2. Open the bucket's **Settings** and turn on the **Public Development URL** (an address like `https://pub-xxxx.r2.dev`). That is `R2_PUBLIC_URL`.
3. Under **R2 → Manage API tokens** create a token with **Object Read & Write** for this bucket. Note the **Access Key ID**, **Secret Access Key**, and the **S3 endpoint** (`https://<account-id>.r2.cloudflarestorage.com`).

## 3. Email (Brevo)

1. Create an account at brevo.com. Under **Senders** add and confirm the sender address (for example the Gmail of the project).
2. Under **SMTP & API → SMTP** note the **login** and create an **SMTP key**.
3. Use host `smtp-relay.brevo.com` and port **2525**. Render's free tier blocks ports 25, 465, and 587, but not 2525.

## 4. The API (Render)

1. Generate an application key on your PC, in the `server` folder: `php artisan key:generate --show`. Keep the output (`base64:...`).
2. On render.com choose **New → Web Service**, connect the `EntryPoint-Server` repository, and pick the branch you want to deploy.
3. Language: **Docker**. Region: **Singapore**. Instance type: **Free**. Health check path: `/up`.
4. Add these environment variables:

| Name | Value |
|---|---|
| `APP_NAME` | `EntryPoint` |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_KEY` | the `base64:...` from step 1 |
| `APP_URL` | your Render address, e.g. `https://entrypoint-api.onrender.com` |
| `PORTAL_URL` | your Cloudflare Pages address (step 5) |
| `CORS_ALLOWED_ORIGINS` | the same Cloudflare Pages address |
| `STUDENT_EMAIL_DOMAIN` | `davao.sti.edu.ph` |
| `LOG_CHANNEL` | `stderr` |
| `DB_CONNECTION` | `mysql` |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | from Aiven |
| `DB_SSL_CA_BASE64` | the one-line certificate from step 1 |
| `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` | `database` |
| `MAIL_MAILER` | `smtp` |
| `MAIL_HOST` | `smtp-relay.brevo.com` |
| `MAIL_PORT` | `2525` |
| `MAIL_SCHEME` | `smtp` |
| `MAIL_USERNAME`, `MAIL_PASSWORD` | the Brevo login and SMTP key |
| `MAIL_FROM_ADDRESS` | the confirmed sender |
| `MAIL_FROM_NAME` | `EntryPoint` |
| `R2_BUCKET` | `entrypoint` |
| `R2_ENDPOINT` | the S3 endpoint |
| `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY` | from the R2 token |
| `R2_PUBLIC_URL` | the `pub-xxxx.r2.dev` address |
| `ADMIN_EMAIL`, `ADMIN_PASSWORD` | the first administrator (password of 8 characters or more; it must be changed at the first sign-in) |
| `SEED_DEMO` | `true` only for a demo with sample data; leave it out for real use |

5. Create the service. The first build takes several minutes. When it is live, open `https://<your-render-address>/up`; a page that says the application is up means the API is running.

## 5. The website (Cloudflare Pages)

1. In Cloudflare open **Workers & Pages → Create → Pages → Connect to Git** and choose `EntryPoint-Client` and its branch.
2. Build command: `npm run build`. Build output directory: `dist`.
3. Environment variables (Production):

| Name | Value |
|---|---|
| `NITRO_PRESET` | `cloudflare-pages` |
| `NUXT_PUBLIC_API_BASE_URL` | `https://<your-render-address>/api` |
| `NUXT_PUBLIC_STUDENT_EMAIL_DOMAIN` | `davao.sti.edu.ph` |
| `NODE_VERSION` | `22` |

4. Deploy. Copy the address it gives (`https://<name>.pages.dev`). Put it into `PORTAL_URL` and `CORS_ALLOWED_ORIGINS` on Render, and let Render redeploy.

## 6. Keep it awake and run the schedule (cron-job.org)

A free Render service falls asleep after about 15 minutes without a request, and the next visitor waits up to a minute. Create a free account at cron-job.org and add a job:

- URL: `https://<your-render-address>/up`
- Schedule: every 10 minutes

This keeps the API awake, so its built-in scheduler keeps running: fines every hour, due-soon notices every hour, closing old visits and removing old tokens every night.

## 7. First sign-in

Open the Cloudflare address, go to the librarian sign-in (`/librarian/login`), and use `ADMIN_EMAIL` and `ADMIN_PASSWORD`. You will be asked to choose a new password. If you set `SEED_DEMO=true`, the sample accounts exist too (their shared password is in `database/seeders`); do not leave a real library on those.

## Limits to know

- Free Render has no shell, so everything first-time is done by the settings above (`php artisan entrypoint:setup` runs at every start and does nothing once the data exists).
- Free Aiven has 1 GB of storage. Free Render sleeps without the keep-awake job. Check each provider's pricing page now and then, since free plans change.
- The phone camera needs https, which Render and Cloudflare provide, so the phone scanner can finally be tried on a real phone.
- For real daily use the first upgrade is a paid Render instance (always awake, and it can send mail on port 587).

## Trouble

- **The site loads but every action fails** — `CORS_ALLOWED_ORIGINS` does not match the website's address exactly (no trailing slash).
- **A 500 error from the API** — set `APP_DEBUG=true` for a minute and read the message, or read the logs in Render.
- **The database refuses to connect** — the certificate (`DB_SSL_CA_BASE64`) is missing or not one line; as a last resort set `DB_SSL_VERIFY=false`.
- **Pictures do not show** — the bucket's public URL is off, or `R2_PUBLIC_URL` is wrong.
- **No verification email** — check the sender is confirmed in Brevo and look in the Junk folder.

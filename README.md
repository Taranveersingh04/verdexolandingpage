# Verdexo Ventures: growth plan landing page

The paid-traffic landing page for [Verdexo Ventures](https://verdexo.vercel.app). It has one goal: get a business to request a free growth plan.

**Live:** https://verdexo.vercel.app/lp

## What's on the page

The page follows the standard high-converting landing page order, and every section ends in the same call to action.

1. **Hero:** a headline that matches the ad, with a real result and a two-step lead form above the fold.
2. **Proof:** ad-dashboard cards built from real campaign reports (client names kept private).
3. **How it works:** a live phone demo of the AI receptionist booking a customer at 9:42 PM.
4. **Services:** AI receptionist, Google & Meta ads, SEO & AI search, content & web.
5. **Work:** a filmstrip of reels we've made.
6. **FAQ:** answers to the usual objections.
7. **Close:** a short call-back form.

### Lead form

- **Step 1** is one-tap choices: services (multi-select) and a budget range.
- **Step 2** is contact details: name, business name or website, and phone or WhatsApp.
- Labels sit above the fields, with inline errors and a mobile number keypad.
- Every CTA opens the same form. If the hero form isn't on screen, it opens as a bottom sheet on phones or a centred pop-up on desktop, so nobody loses their place.
- The success state is personalised and explains what happens next.

### Mobile

- The first form step, including its button, fits on the first phone screen.
- The proof cards sit in a native swipe row with arrows, a progress bar and a counter.
- Services show as 3D cards, and there's a menu for in-page links.

### Accessibility and performance

- All text meets WCAG AA contrast, and form outlines meet 3:1.
- Reduced motion is respected.
- Motion uses only transforms and opacity, and scrolling is native (no scroll-jacking library).
- The page is a single HTML file with no build step. The logo, 3D renders, reel covers and sky are embedded as compressed WebP, so they never fail to load. Only the short reel videos load separately.

## Structure

```
lp/index.html        final page (version 2)
lp/v1/index.html     version 1, kept for A/B testing
lp/img/3d/           3D renders and sky (used by v1; the final page embeds its own copies)
media/previews/      muted reel previews (MP4, plus poster JPGs for v1)
vercel.json          routes / and /lp to the page
```

## Run locally

```bash
npx serve .
```

Then open http://localhost:3000/lp/index.html.

## Connect the form

Both forms email each lead to `demandgenleads@verdexoventures.com` through [FormSubmit](https://formsubmit.co), set by `LEAD_EMAIL` near the top of the script in `lp/index.html`. The very first submission makes FormSubmit send an activation email to that inbox: click **Activate Form** once, or leads are not delivered. Each email has a clear subject, the lead's details, the time in IST and any `gclid`/`utm_*` values from the ad click. If sending fails, the visitor sees an error with the phone and WhatsApp links instead of a fake success message. A `lead_submit` event (or `lead_send_failed`) is also pushed to `dataLayer` for Google Ads and Meta conversion tracking.

## Deploy

The folder is static, so it deploys as-is to Vercel (or any static host):

```bash
vercel deploy --prod
```

## AI chat

The chat button (bottom right) talks to `lp/server/lp-chat.php`, which is uploaded to the site root as `/lp-chat.php`. It forwards the conversation to Google Gemini's free tier and keeps the API key on the server, never in the page.

1. Create a free key at https://aistudio.google.com/apikey.
2. Copy `lp/server/chat-config.example.php` to `lp-chat-config.php` one folder **above** `public_html` (or to `public_html/lp-assets/chat-config.php`) and paste the key into it.

With no key, a used-up free quota or any Google error, the chat switches to tap-to-answer FAQ buttons and an inline name/number card, so leads are still captured. Chat leads go to the same inbox as the forms, with the whole conversation attached. The server limits each visitor to 40 messages per 10 minutes. Note: on Gemini's free tier, Google may use conversations to improve its products.

For local testing, put the key in `~/verdexo-landing-page-review/.gemini-key` (outside the repo); the local dev server reads it.

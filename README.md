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
- A sticky "Get my plan" bar appears once the hero form is out of view.
- The proof cards pin and slide sideways as you scroll, with arrows, a progress bar and a counter.
- Services show as 3D cards, and there's a menu for in-page links.

### Accessibility and performance

- All text meets WCAG AA contrast, and form outlines meet 3:1.
- Reduced motion is respected.
- Motion uses only transforms and opacity, and scrolling is native (no scroll-jacking library).
- The page is a single HTML file with no build step. The logo and brand tile are embedded, so they never fail to load.

## Structure

```
lp/index.html        final page (version 2)
lp/v1/index.html     version 1, kept for A/B testing
lp/img/3d/           3D service renders and the sky backdrop
media/previews/      muted reel previews (MP4 + poster JPG)
vercel.json          routes / and /lp to the page
```

## Run locally

```bash
npx serve .
```

Then open http://localhost:3000/lp/index.html.

## Connect the form

Submissions are currently kept in the browser (`window.verdexoLeads`). To receive leads, set `FORM_ENDPOINT` near the top of the script in `lp/index.html` to a Google Apps Script web app, Formspree or CRM webhook URL. Each lead is sent as JSON. A `lead_submit` event is also pushed to `dataLayer` for Google Ads and Meta conversion tracking.

## Deploy

The folder is static, so it deploys as-is to Vercel (or any static host):

```bash
vercel deploy --prod
```

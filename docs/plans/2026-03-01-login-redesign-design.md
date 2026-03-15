# Login Page Redesign - Premium Gaming Experience

## Goal

Redesign the login page from a plain, template-like centered form to a premium, immersive gaming login experience that conveys quality and excitement.

## Design Decisions

- **Layout**: Centered card over full-viewport dark background
- **Color mood**: Deep navy/midnight blue gradient with electric blue accents
- **Tenant support**: Tenant colors override accent (glow, button, focus rings); dark background stays consistent
- **Scope**: New auth layout component; login page updated to use it; all other auth pages can adopt it later

## Layout & Background

- Full-viewport dark background with gradient: deep navy (`#0a0e27`) to midnight blue (`#131a3d`)
- Subtle radial glow behind the card in electric blue (`rgba(37, 99, 235, 0.15)`)
- Subtle CSS dot-grid pattern overlay at very low opacity for texture

## Card Design

- `max-w-md` centered card
- Semi-transparent: `bg-white/95` (light) / `bg-slate-900/90` (dark) with `backdrop-blur-xl`
- `shadow-2xl` with faint blue glow, `border border-white/10`
- `rounded-2xl` for modern feel
- Generous padding: `px-8 py-10`

## Card Interior

- Logo at top center: tenant logo or Chinga Games icon, `h-12`
- Title: "Log in to your account" - bold, centered
- Subtitle: "Enter your credentials below" - muted, centered
- Form fields: existing Input components, height `h-10`, subtle inner shadow
- Remember me checkbox (left) + Forgot password link (right) on same row
- Login button: full-width gradient (electric blue -> lighter blue), hover glow effect
- Sign-up link below button

## Tenant Branding

- Tenant primary color replaces electric blue in: radial glow, button gradient, focus rings
- Tenant logo replaces default icon
- Dark background remains consistent across tenants
- "Powered by Chinga Games" footer below card in tenant context

## Responsive

- Mobile: card full-width, simplified gradient, appropriate padding
- Desktop: card floats centered with full dramatic background

## What Stays the Same

- All form functionality (fields, validation, errors, loading states)
- Inertia form submission via `store.form()`
- Tab navigation order (1-5)
- `data-test` attributes
- Remember me + forgot password + sign-up conditional links
- Password cleared on success (`resetOnSuccess`)

## Files to Create/Modify

1. **New**: `resources/js/layouts/auth/auth-premium-layout.tsx` - new premium layout
2. **Modify**: `resources/js/layouts/auth-layout.tsx` - switch to premium layout
3. **Modify**: `resources/css/app.css` - add premium background/glow CSS custom properties
4. **Modify**: `resources/js/pages/auth/login.tsx` - adjust form styling (remember me row, button gradient)

# Admin First-Login Welcome Panel

## Page Structure Draft

The welcome panel is a fullscreen modal shown after the first successful admin login. It does not change the admin navigation, permissions, or page layout. It only overlays the current backend page. The presentation is a single welcome letter rather than a multi-card dashboard.

### Layout blocks

1. Top action bar
- Badge: `Before You Start`
- Language button: `English` / `中文`
- Close button: `Close`

2. Single welcome-letter body
- Title: `Welcome to GEOFlow`
- Subtitle: `Hi, welcome to GEOFlow.`
- Body: article-style reading flow instead of module cards
- Content order:
  - greeting
  - system positioning
  - capabilities and scenarios
  - design logic
  - roadmap
  - author introduction

3. Contact actions
- Intro text
- `Author X Profile`
- `Project GitHub`
- `Changelog`

4. Reopen entry
- Add `Project Intro` to the admin footer
- Clicking it reopens the panel without resetting the dismissal state

## Field Key List

The welcome panel content is organized by structured keys so it can later move into a stricter i18n or CMS-based content layer.

### Meta
- `meta.badge`
- `meta.switch_label`
- `meta.close`
- `meta.links_label`
- `meta.author_link`
- `meta.github_link`
- `meta.changelog_link`

### Letter
- `letter.title`
- `letter.subtitle`
- `letter.blocks[]`
- `letter.blocks[].type`
- `letter.blocks[].content`
- `letter.blocks[].items[]`

### Footer reopen entry
- `footer.project_intro_link`

## Backend Integration Logic

### Triggering rules
- Auto-open after the first admin login
- Once dismissed, it no longer auto-opens
- The footer `Project Intro` action can always reopen it manually

### Persistence
- `admins.welcome_dismissed_at`
- `NULL` means the admin has never dismissed the panel
- Closing the panel updates the field to `CURRENT_TIMESTAMP`

### Integration points
- `admin/index.php`
  - Keep the existing login redirect flow intact
- `admin/includes/footer.php`
  - Render the modal and the footer reopen button
- `admin/welcome-dismiss.php`
  - Persist dismissal after CSRF-validated authenticated POST

### Language behavior
- The welcome panel defaults to Chinese
- The top-right button switches the panel to English
- This switch affects only the welcome panel, not the global admin locale

### Safety boundaries
- No changes to permissions
- No changes to the login flow
- No route changes for existing admin pages
- Dismissal requires authenticated admin session and CSRF validation

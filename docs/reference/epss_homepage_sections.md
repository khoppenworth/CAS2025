# EPSS homepage layout notes (epss.gov.et)

Source: https://epss.gov.et/ (captured via `curl -L --max-time 20 -s https://epss.gov.et/`)

## Global layout
- **Top navigation**: Bootstrap 5 "navbar-professional" with left-aligned logo, right-aligned mobile toggler, and a central nav list with icon + label items and multi-column dropdowns (mega menu for Marketing).
- **Hero**: Full-width carousel with multiple slides, progress indicators, slide badge, and CTA buttons ("Active tenders", "Latest News").

## Primary nav items
- Home
- About (dropdown: Company Overview + Leadership)
- Procurement (dropdown: Active Opportunities + Resources)
- Marketing (mega menu: Marketing Portal + Customer Experience)
- Resources (dropdown: Documentation + Forms & Templates)
- News (dropdown: Latest Updates)
- Contact (direct link)

## Main homepage sections (top to bottom)
- Carousel / hero (`section.carousel-modern`)
- Statistics (`section.stats-modern`)
- Services (`section.services-modern`)
- Events (`section.events-modern`)
- News (`section.news-modern`)
- Gallery (`section.gallery-showcase`)
- Awards (`section.awards-modern`)
- Partners (`section.partners-modern`)
- Quick actions / CTA band (`section.quick-actions-modern`)
- Footer (`footer.footer-modern`) with brand block, quick access, services, contact/support, mission strip, and certifications.

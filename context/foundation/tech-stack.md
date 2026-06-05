---
starter_id: laravel
package_manager: composer
project_name: koszykomat
hints:
  language_family: php
  team_size: solo
  deployment_target: self-host
  ci_provider: github-actions
  ci_default_flow: auto-deploy-on-merge
  bootstrapper_confidence: verified
  path_taken: standard
  quality_override: false
  self_check_answers: null
  has_auth: true
  has_payments: false
  has_realtime: false
  has_ai: true
  has_background_jobs: true
---

## Why this stack

Solo developer shipping a price-comparison web app in 3 after-hours weeks, with OAuth-only auth, a nightly data-refresh job, and AI-assisted leaflet ingestion. Laravel is the recommended default for a PHP web app and its bootstrapper confidence is verified, so scaffolding will be smooth. The fit is direct: Socialite covers OAuth login, the scheduler plus queues cover the nightly refresh on a server that already provides cron, and the promo-mechanics rule engine is plain testable domain code over Eloquent and MySQL. Leaflet vision processing is not first-class in any starter and lands as an external vision-API integration invoked from queued jobs. Deployment targets the developer's own dedicated server (DirectAdmin-managed, PHP + MySQL + cron), so CI on GitHub Actions auto-deploys on merge via an SSH-based release step rather than a container push. Local development runs in ddev containers (nginx-fpm, PHP 8.3, MySQL 5.7 — matching the server's maximums; composer platform pinned to php 8.3.0) — containerization is local-only; the production target stays a classic server. Payments and realtime are out of scope per the PRD.

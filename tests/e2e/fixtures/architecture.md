# Browser Workflow Architecture

This architecture document defines a Laravel modular monolith with an Inertia
React frontend, PostgreSQL persistence, Redis-backed queues, and private
S3-compatible object storage.

Document lifecycle authorization remains server-side. The browser only renders
actions explicitly authorized by the application.

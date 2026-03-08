# WP POS Sync (WooCommerce Bridge)

A high-performance, modular WordPress plugin designed to bridge WooCommerce with a React-based Point of Sale (POS) system. It ensures strict error isolation, real-time synchronization, and premium UI components for restaurant management.

## 🏗️ Architecture

The plugin follows a modern, modular architecture for maximum reliability and maintainability.

- **`Plugin.php` (Orchestrator)**: The core manager that automatically boots all modules found in the `Modules/` directory. It also exposes a `posSystemStatus` GraphQL field for real-time health monitoring of the backend.
- **`AbstractModule.php`**: The foundational class for all modules. It provides a try-catch boot sequence to ensure that a failure in one module (e.g., a shipping error) never crashes the entire site.

## 📦 Modules

### 🛡️ SecurityModule
Handles cross-origin resource sharing (CORS) and security headers, ensuring the React POS can safely communicate with the WordPress backend from different domains.

### 📝 RulesModule
Manages product-specific synchronization rules, including complex item modifiers (choice groups). It exposes these rules via GraphQL to the POS dashboard.

### 🚚 ShippingModule
Consolidates WooCommerce shipping zones and custom POS shipping types. It provides safe GraphQL resolvers for `methodId` and `instanceId`, preventing internal server errors during checkout.

### 🚀 CacheModule
A performance-oriented module that utilizes WordPress Transients to cache heavy GraphQL queries and configuration data, significantly reducing server load.

### ⚙️ ConfigModule
The central hub for restaurant settings.
- **Opening Hours**: Manages complex weekly schedules.
- **Horario Sidebar**: Injects a premium, glassmorphism-styled sidebar into the shop frontend, featuring a functional analog clock and session progress "pie chart".

### 🔄 SyncModule
Implements Server-Sent Events (SSE) to provide live status updates between WooCommerce and the POS terminal, ensuring order status changes are reflected instantly.

## 🚀 Deployment

Deployment is handled via the root `deploy.cjs` script:
```bash
node deploy.cjs plugin
```
This syncs the `WP_POS_SYNCH` folder directly to the production WordPress `plugins` directory.

## 🛠️ Development

This plugin is part of the AZZARO POS ecosystem. It is intended to be used alongside the React-based POS Dashboard.

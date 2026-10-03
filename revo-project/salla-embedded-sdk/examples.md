# Common Examples

This page contains small, focused snippets for common embedded app workflows.

## Initialize app and use layout data

```javascript
import { embedded } from "@salla.sa/embedded-sdk";

async function bootstrap() {
  const { layout } = await embedded.init({ debug: true });

  document.documentElement.classList.toggle("dark", layout.theme === "dark");
  document.documentElement.lang = layout.locale;
  document.documentElement.dir = layout.dir;

  embedded.ready();
}
```

## Handle checkout payment result

```javascript
const unsubscribeCheckout = embedded.checkout.onResult((result) => {
  if (result.success) {
    embedded.ui.toast.success("Payment completed");
    return;
  }
  embedded.ui.toast.error(result.error?.message || "Payment failed");
});

embedded.checkout.create(
  { type: "addon", slug: "premium-analytics", quantity: 1 },
  { context: { source: "pricing-page" } }
);
```

## Sync internal app navigation with host Navbar

```javascript
const items = await Promise.all([
  embedded.nav.addNavItem({ title: "Overview", value: "overview", url: "/apps/my-app/overview", active: true }),
  embedded.nav.addNavItem({ title: "Settings", value: "settings", url: "/apps/my-app/settings" }),
]);

const unsubscribeNav = embedded.nav.onNavItemClick(({ id, value, url }) => {
  setActiveTab(value); // your internal route/tab state
  embedded.nav.updateNavItem({ id, active: true });
  console.log("Clicked host URL:", url);
});
```

## Confirm before destructive action

```javascript
async function deleteCategory(categoryId) {
  const { confirmed } = await embedded.ui.confirm({
    title: "Delete Category?",
    message: "This action cannot be undone.",
    confirmText: "Delete",
    variant: "danger",
  });

  if (!confirmed) return;
  await api.deleteCategory(categoryId);
  embedded.ui.toast.success("Category deleted");
}
```

## Toggle breadcrumbs for focused flows

```javascript
function onRouteChange(route) {
  if (route === "checkout-flow") embedded.ui.breadcrumbs.hide();
  else embedded.ui.breadcrumbs.show();
}
```

## Safe cleanup pattern

```javascript
const cleanups = [];

cleanups.push(embedded.nav.onActionClick(handleActionClick));
cleanups.push(embedded.nav.onNavItemClick(handleSubNavClick));

function teardown() {
  cleanups.forEach((fn) => fn());
  embedded.nav.clearAction();
}
```

:::highlight blue 💡
**Best Practices**
*   Keep host-level controls (`nav`, `breadcrumbs`, `loading`) synchronized with your internal app state.
*   Store Navbar item IDs returned by `addNavItem()` so updates/removals are deterministic.
*   Use `try...finally` for async flows that show loading or lock interaction.
    :::

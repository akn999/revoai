# Clearing Navbar Actions


The `clearAction` method allows you to remove any custom buttons you've added to the dashboard's top navigation bar.

## Usage

Call this whenever the current page or context no longer requires the primary action.

```javascript
import { embedded } from "@salla.sa/embedded-sdk";

// Remove all custom navbar buttons
embedded.nav.clearAction();
```

## When to Use

*   **Context Switching**: If the merchant moves to a section of your app that doesn't have a "Create" or "Save" action, clear the navbar to avoid confusing the user.

*   **Cleanup**: While the dashboard cleans up automatically on unmount, manually clearing actions during component lifecycle changes (like in a `useEffect` cleanup) is a best practice.

:::info[Best Practices]
*   **Conditional Presence**: Only show navbar actions when they are actually useful. A permanent "Save" button that does nothing most of the time creates a poor user experience.
*   **Component Unmounting**: If you are using a framework like React or Vue, call `clearAction()` when your main component or a specific page component unmounts.
    :::

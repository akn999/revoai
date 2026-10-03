# App Design Guidelines

To provide a seamless and trustworthy experience for merchants, embedded apps should feel like a native extension of the Salla Merchant Dashboard. This guide outlines the visual and functional requirements for designing your app.

### What you'll learn:
The App Design Guidelines:
- [Key Concepts](#key-concepts)
- [Using SDK Components](#using-sdk-components)
- [Visual Identity](#visual-identity)
- [Layout and Spacing](#layout-and-spacing)
- [Fonts](#salla-fonts)
- [Embedded App Theme Support](#theme-support)

## Key Concepts

<CardGroup cols={2}>
  <Card title="SDK Components" icon="material-outline-category">
    Mandatory use of native UI components for core interactions.
  </Card>

  <Card title="Visual Identity" icon="material-outline-palette">
    Colors, typography, and iconography from the Salla Brand.
  </Card>
  <Card title="Layout & Spacing" icon="material-outline-grid_view">
    Grid systems and spacing rules for consistent alignment.
  </Card>
  <Card title="Theme Support" icon="material-outline-contrast">
    Implementing Dark and Light mode synchronization.
  </Card>
</CardGroup>

## Using SDK Components

Using the Embedded SDK Modules is not just a recommendation—it is a requirement for core dashboard interactions. This ensures that users don't face fragmented UI patterns.

### Use Methods, Not Events
**Crucial:** Always use the high-level SDK methods (e.g., `embedded.ui.toast.success`) instead of sending `postMessage` events directly.
*   **Future Proofing**: The internal message structure or event names may change in future versions.
*   **Consistency**: SDK methods handle validation and error states that direct events might bypass.
*   **Abstraction**: The SDK provides a clean API that hides the complexity of the underlying communication protocol.

## Visual Identity

Your app should adhere to the [Salla Brand Guidelines](https://brand.salla.com/) to maintain visual continuity.

### Brand Colors
Salla's core palette defines the look and feel of the Merchant Dashboard. Use these HSL values (or their hex equivalents) for your components to ensure they match the merchant's current theme.

<Tabs>
  <Tab title="Light Mode">
    | Color | HSL Value | Hex Equivalent | Use Case |
    | :--- | :--- | :--- | :--- |
    | `primary` | `189 100% 17%` | `#004d5b` | Main brand color, deep teal headings. |
    | `secondary` | `163 100% 82%` | `#73fcd7` | Mint/Teal accents, active states. |
    | `success` | `157 100% 34%` | `#00b259` | Positive feedback, active status. |
    | `danger` | `358 89% 64%` | `#f5434a` | Errors and destructive actions. |
    | `bg-main` | `0 0% 97%` | `#f8f8f8` | Main dashboard workspace background. |
  </Tab>
  <Tab title="Dark Mode">
    | Color | HSL Value | Hex Equivalent | Use Case |
    | :--- | :--- | :--- | :--- |
    | `primary` | `166 70% 84%` | `#baefe3` | Light teal text and primary elements. |
    | `secondary` | `166 70% 84%` | `#baefe3` | Accent highlights in dark mode. |
    | `success` | `157 100% 34%` | `#00b259` | Standard success green. |
    | `danger` | `358 89% 64%` | `#f5434a` | Standard error red. |
    | `bg-main` | `220 5% 12%` | `#1d1e20` | Dark surface background. |
  </Tab>
</Tabs>

### Typography
Salla uses the **PingARLT** font family for a professional, readable experience across Arabic and English.
*   **Consistency**: Ensure your app uses a clean sans-serif stack that matches the dashboard's weight and spacing.


### Iconography
Salla utilizes the **Hugeicons** library to maintain a clean, geometric, and modern visual language.

*   **Consistency**: Use [Hugeicons](https://hugeicons.com/) to match the native dashboard aesthetic.
*   **Stroke to Shape**: Ensure icons maintain consistent stroke weights. If you are using SVG versions, convert strokes to shapes before scaling to maintain visual integrity.
*   **Native Context**: SDK methods will use the same icons. For example `setAction` allow you to pass icon class names directly. Use the standard Hugeicons class naming convention (e.g., `hgi hgi-stroke hgi-star`).

:::check[Embedded App Banner]
It is imperative to add Embedded App Banner image with `1420×520 px`in your App Card for a smooth App publishing journey.
On the Partners Portal, go to My Apps -> Select the App-> App Details -> Start publishing your App-> App Features -> Embedded App Banner.

![image.png](https://api.apidog.com/api/v1/projects/451700/resources/376488/image-preview)

:::

## Layout & Spacing

### The "No-Chrome" Rule
Your app runs **inside** the Salla Dashboard. It should not attempt to replicate the dashboard's navigation or layout.
*   **No Sidebar**: Do not include a side navigation menu. Use the dashboard's native app navigation.
*   **No Top Navbar**: Do not add a top header or breadcrumbs inside your iframe. Use `embedded.page.setTitle()` and `embedded.nav.setAction()` instead.
*   **Full Width**: Your content should expand to fill the provided iframe container.

### Good vs. Bad Integration

<Tabs>
  <Tab title="✅ The Good Integration">
     1. An integrated app uses native dashboard components and focuses only on its own functional logic.
      2. The app adheres to Salla's Dashboard theme and colors to provide visual consistency and native UI experience.
       ![Integrated App](https://api.apidog.com/api/v1/projects/451700/resources/370555/image-preview)
  </Tab>
  <Tab title="❌ The Bad Integration">
    1. A "Nested Dashboard" effect where the app includes its own sidebar and navbar, wasting space and confusing the user.
    2. The app is using custom colors that do not adhere to Salla's Dashboard theme.
        ![Poor Integration](https://api.apidog.com/api/v1/projects/451700/resources/370556/image-preview)
  </Tab>
</Tabs>

## Salla Fonts

Use the official Salla Fonts in your embedded apps to ensure a consistent look and feel across the Salla platform.

Available font files:
- [PingARLT-Regular](https://cdn.salla.network/fonts/lib/pingarlt/PingARLT-Regular.woff2)
- [PingARLT-Medium](https://cdn.salla.network/fonts/lib/pingarlt/PingARLT-Medium.woff2)
- [PingARLT-Bold](https://cdn.salla.network/fonts/lib/pingarlt/PingARLT-Bold.woff2)

## Embedded App Theme Support

The Salla Dashboard supports dynamic theme switching (Light & Dark). Your app **must** respond to these changes.

*   **Syncing**: Listen for theme changes using `embedded.onThemeChange()`.
*   **Contrast**: Ensure all elements are legible in both light and dark modes by using the dashboard's semantic color variables where possible.

## Next Steps

<CardGroup cols={2}>
  <Card title="Create an Embedded App" href="https://docs.salla.dev/embedded-sdk/create-app" icon="material-outline-apps">
    Browse the full list of available modules to enhance your app's functionality.
  </Card>
  <Card title="Start Developing" href="https://docs.salla.dev/embedded-sdk/playground" icon="material-outline-code">
    Explore the testing playground to boost your embedded app implementation.
  </Card>
</CardGroup>

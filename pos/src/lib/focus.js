// The design system's focus ring: a solid 2px `focus` outline with a 2px offset
// (README, Interaction). NativeWind turns these into :focus-visible rules on web;
// native platforms ignore them and use their own focus handling.
export const FOCUS_RING =
  'focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus';

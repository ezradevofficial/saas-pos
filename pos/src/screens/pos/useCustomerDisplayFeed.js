import { useEffect, useMemo, useRef } from 'react';
import { displayChannel, displayState } from '../../pos/customerDisplay';
import { usePosCart, usePosData } from '../../pos/PosProvider';
import { useMedia } from '../../theme/media';
import { tillTheme, useTillTheme } from '../../theme/TillTheme';
import { useMoneyText } from './format';
import { useDualTotal } from './SellScreen';

/**
 * LAY-05: publishes the current sale to the customer display (another tab,
 * or the in-app display) whenever it changes, and again when a display
 * says hello. Only what the customer may see leaves the till's screens.
 */
export function useCustomerDisplayFeed(channel = displayChannel()) {
  const { catalogue } = usePosData();
  const { cart, computed } = usePosCart();
  const text = useMoneyText();
  const { theme, mode, logo } = useTillTheme();
  const logoUri = useMedia(catalogue?.layout?.customer_display?.show_logo === false ? null : logo);
  const dual = useDualTotal(computed?.totals.total_minor);

  const state = useMemo(
    () =>
      displayState({
        catalogue,
        computed,
        cart,
        display: catalogue?.layout?.customer_display,
        text,
        theme: tillTheme(theme, mode),
        logo: logoUri,
        second: dual ? text(dual.amount, dual.currency) : null,
      }),
    [catalogue, computed, cart, text, theme, mode, logoUri, dual],
  );

  const latest = useRef(state);
  latest.current = state;

  useEffect(() => channel.listen((message) => message?.type === 'hello' && channel.post({ type: 'state', state: latest.current })), [channel]);
  useEffect(() => {
    channel.post({ type: 'state', state });
  }, [channel, state]);
}

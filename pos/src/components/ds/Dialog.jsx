import { useId } from 'react';
import { useTranslation } from 'react-i18next';
import { Modal, Pressable, ScrollView, Text, View } from 'react-native';
import { cn } from '../../lib/cn';
import { Button } from './Button';

const WIDTH = { md: 'md:w-1/2 xl:w-1/3', lg: 'md:w-2/3 xl:w-1/2' };

/**
 * A focused window for a decision. Props mirror web/src/components/ds/Dialog.jsx
 * (open, title, onClose, footer, children, size). The scrim is the ink colour
 * at reduced opacity (a token, never a typed colour); tapping it closes, as
 * Escape does on the web (the Android back button too, through Modal).
 */
export function Dialog({ open, title, onClose, footer, children, size = 'md', testID }) {
  const { t } = useTranslation();
  const titleId = useId();
  if (!open) return null;

  return (
    <Modal transparent visible animationType="fade" onRequestClose={onClose} supportedOrientations={['portrait', 'landscape']}>
      <View className="flex-1 items-center justify-center p-4">
        <Pressable accessibilityRole="none" accessible={false} onPress={onClose} className="absolute bottom-0 left-0 right-0 top-0 bg-ink opacity-40" />
        <View
          testID={testID ?? 'dialog'}
          role="dialog"
          aria-labelledby={titleId}
          aria-modal
          className={cn('max-h-full w-full rounded-lg border border-border bg-surface-200 shadow-lg', WIDTH[size] ?? WIDTH.md)}
        >
          <View className="flex-row items-center justify-between gap-4 px-5 pb-2 pt-5">
            <Text nativeID={titleId} accessibilityRole="header" className="min-w-0 flex-1 font-sans text-h2 font-semibold text-ink">
              {title}
            </Text>
            {onClose ? (
              <Button variant="ghost" onPress={onClose} accessibilityLabel={t('ds.dialog.close')} className="px-3">
                {t('ds.dialog.closeShort')}
              </Button>
            ) : null}
          </View>
          <ScrollView className="flex-shrink" contentContainerClassName="gap-4 px-5 pb-5" keyboardShouldPersistTaps="handled">
            {children}
          </ScrollView>
          {footer ? <View className="flex-row flex-wrap justify-end gap-2 border-t border-border px-5 py-3">{footer}</View> : null}
        </View>
      </View>
    </Modal>
  );
}

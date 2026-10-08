import { useId, useState } from 'react';
import { Text, TextInput, View } from 'react-native';
import { cssInterop } from 'nativewind';
import { cn } from '../../lib/cn';

// The placeholder colour is a token too: placeholderClassName feeds
// placeholderTextColor (NativeWind 4 has no built-in mapping for it).
cssInterop(TextInput, {
  className: { target: 'style', nativeStyleToProp: { textAlign: true } },
  placeholderClassName: { target: false, nativeStyleToProp: { color: 'placeholderTextColor' } },
});

/**
 * Props mirror web/src/components/ds/TextField.jsx: label, help, error,
 * prefix, suffix, required, value, plus any TextInput prop. `onChange`
 * receives { target: { value } } like the web input; `onChangeText` the
 * text. 48px tall for touch.
 */
export function TextField({ label, help, error, prefix, suffix, required, className, id, onChange, onChangeText, editable, disabled, ...rest }) {
  const autoId = useId();
  const inputId = id ?? autoId;
  const [focused, setFocused] = useState(false);
  const inactive = disabled || editable === false;

  const handleChange = (text) => {
    onChangeText?.(text);
    onChange?.({ target: { value: text } });
  };

  return (
    <View className={cn('min-w-0 gap-1', className)}>
      {label ? (
        <Text nativeID={`${inputId}-label`} className="font-sans text-label text-ink">
          {label}
          {required ? <Text className="text-ink-muted"> *</Text> : null}
        </Text>
      ) : null}
      <View
        testID="text-field-box"
        className={cn(
          'h-12 flex-row items-center rounded-md border bg-surface-200',
          error ? 'border-danger' : focused ? 'border-focus' : 'border-border-strong',
          inactive && 'border-border bg-surface-300',
        )}
      >
        {prefix ? <Text className="pl-3 font-sans text-body-lg text-ink-muted">{prefix}</Text> : null}
        <TextInput
          nativeID={inputId}
          accessibilityLabel={label}
          aria-labelledby={label ? `${inputId}-label` : undefined}
          aria-invalid={error ? true : undefined}
          accessibilityHint={error || help || undefined}
          editable={!inactive}
          onFocus={() => setFocused(true)}
          onBlur={() => setFocused(false)}
          onChangeText={handleChange}
          className="h-12 flex-1 px-3 font-sans text-body-lg text-ink outline-none"
          placeholderClassName="text-ink-muted"
          {...rest}
        />
        {suffix ? <Text className="pr-3 font-sans text-body-lg text-ink-muted">{suffix}</Text> : null}
      </View>
      {error ? (
        <Text role="alert" className="font-sans text-caption text-danger">
          {error}
        </Text>
      ) : help ? (
        <Text className="font-sans text-caption text-ink-muted">{help}</Text>
      ) : null}
    </View>
  );
}

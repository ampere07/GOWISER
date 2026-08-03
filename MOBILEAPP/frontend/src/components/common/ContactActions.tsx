import React, { useCallback } from 'react';
import { View, Text, Pressable, StyleSheet, Alert, Linking, Platform } from 'react-native';
import { Phone, MessageSquare } from 'lucide-react-native';
import { TECH_SPIEL_SMS } from '../../services/smsTemplateRegistry';

/**
 * A subscriber phone number with Call and SMS beside it.
 *
 * Replaces selecting a number, copying it, leaving the app and pasting it into
 * the dialer — four steps a technician repeats at every stop, standing outside
 * a gate. Both buttons hand off to the device's own apps through `tel:` and
 * `sms:`, so nothing is dialled or sent by us: the technician still sees the
 * dialer and still presses send. That matters for the SMS in particular — the
 * template is prefilled, not dispatched.
 *
 * Renders as plain selectable text when there is no usable number, rather than
 * showing buttons that would open an empty dialer.
 */

interface ContactActionsProps {
  /** The number as recorded. Formatting is stripped before it reaches the OS. */
  value?: string | null;
  /** Message prefilled into the SMS composer. Defaults to the tech spiel. */
  smsBody?: string;
  valueStyle?: any;
  /** Shown when no number is recorded. */
  emptyLabel?: string;
  accent?: string;
}

/**
 * Reduces a recorded number to something `tel:` and `sms:` will accept.
 *
 * Keeps a leading '+' — Philippine numbers are stored both as 09xxxxxxxxx and
 * +639xxxxxxxxx, and dropping the plus turns an international number into an
 * unroutable one. Everything else non-numeric goes: the column is free text and
 * carries spaces, dashes, brackets and the occasional '/' between two numbers.
 */
export const toDialable = (value?: string | null): string => {
  if (!value) return '';

  const trimmed = String(value).trim();
  if (trimmed === '') return '';

  // Only the first number when two were typed into one field ("0917… / 0918…").
  // Dialling a concatenation of both would fail silently.
  const [first] = trimmed.split(/[\/,;]|\s+or\s+/i);

  const digits = (first ?? '').replace(/[^\d+]/g, '');

  // A '+' anywhere but the front is a typo, not a country code.
  const normalised = digits.startsWith('+')
    ? '+' + digits.slice(1).replace(/\+/g, '')
    : digits.replace(/\+/g, '');

  // Shorter than this is a truncated record, not a number worth dialling.
  return normalised.replace(/\D/g, '').length >= 7 ? normalised : '';
};

const ContactActions: React.FC<ContactActionsProps> = ({
  value,
  smsBody = TECH_SPIEL_SMS.body,
  valueStyle,
  emptyLabel = 'Not provided',
  accent = '#2563eb',
}) => {
  const dialable = toDialable(value);

  const open = useCallback(async (url: string, action: string) => {
    try {
      // canOpenURL is checked on native only. On web it reports false for tel:
      // and sms: in several browsers that then handle the link perfectly well,
      // so trusting it there would disable a working button.
      if (Platform.OS !== 'web') {
        const supported = await Linking.canOpenURL(url);

        if (!supported) {
          Alert.alert('Unavailable', `This device cannot ${action} from the app.`);
          return;
        }
      }

      await Linking.openURL(url);
    } catch (err: any) {
      Alert.alert('Error', `Could not ${action}: ${err?.message ?? 'unknown error'}`);
    }
  }, []);

  const handleCall = useCallback(
    () => open(`tel:${dialable}`, 'start a call'),
    [open, dialable]
  );

  const handleSms = useCallback(() => {
    // iOS separates the body with '&', Android and most others with '?'. Getting
    // this wrong drops the prefilled message and opens an empty composer, which
    // is the failure this whole feature exists to avoid.
    const separator = Platform.OS === 'ios' ? '&' : '?';

    open(`sms:${dialable}${separator}body=${encodeURIComponent(smsBody)}`, 'open messages');
  }, [open, dialable, smsBody]);

  if (!dialable) {
    return (
      <Text style={valueStyle} selectable={true}>
        {value?.trim() ? value : emptyLabel}
      </Text>
    );
  }

  return (
    <View style={styles.row}>
      <Text style={[valueStyle, styles.number]} selectable={true}>
        {value}
      </Text>

      <View style={styles.actions}>
        <Pressable
          onPress={handleCall}
          accessibilityRole="button"
          accessibilityLabel={`Call ${dialable}`}
          // Generous hit area: these are tapped one-handed, outdoors, often in
          // gloves. The visual button stays small so the row does not grow.
          hitSlop={{ top: 8, bottom: 8, left: 8, right: 8 }}
          style={({ pressed }) => [
            styles.button,
            { borderColor: accent, opacity: pressed ? 0.6 : 1 },
          ]}
        >
          <Phone width={14} height={14} color={accent} />
          <Text style={[styles.buttonText, { color: accent }]}>Call</Text>
        </Pressable>

        <Pressable
          onPress={handleSms}
          accessibilityRole="button"
          accessibilityLabel={`Send SMS to ${dialable}`}
          hitSlop={{ top: 8, bottom: 8, left: 8, right: 8 }}
          style={({ pressed }) => [
            styles.button,
            { borderColor: accent, opacity: pressed ? 0.6 : 1 },
          ]}
        >
          <MessageSquare width={14} height={14} color={accent} />
          <Text style={[styles.buttonText, { color: accent }]}>SMS</Text>
        </Pressable>
      </View>
    </View>
  );
};

const styles = StyleSheet.create({
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    flexWrap: 'wrap',
    gap: 8,
    width: '100%',
  },
  number: { flexShrink: 1 },
  actions: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  button: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 4,
    paddingVertical: 4,
    paddingHorizontal: 10,
    borderWidth: 1,
    borderRadius: 9999,
  },
  buttonText: { fontSize: 12, fontWeight: '600' },
});

export default ContactActions;

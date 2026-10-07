import type * as React from 'react';

export type Tone = 'neutral' | 'info' | 'success' | 'warning' | 'danger' | 'accent';
export type IconName = 'check' | 'x' | 'alert' | 'info' | 'cloud' | 'sync' | 'offline' | 'chevron' | 'clock';
export interface MoneyValue { amount: number; currency: string }

export interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  /** primary = main action; secondary = default; ghost = low emphasis; danger = destructive; pay = the one POS charge action. */
  variant?: 'primary' | 'secondary' | 'ghost' | 'danger' | 'pay';
  /** lg is 48px tall: use on POS and phone screens. */
  size?: 'md' | 'lg';
  icon?: IconName;
  block?: boolean;
  loading?: boolean;
}
export declare function Button(props: ButtonProps): React.ReactElement;

export interface TextFieldProps extends Omit<React.InputHTMLAttributes<HTMLInputElement>, 'prefix'> {
  label?: string; help?: string; error?: string;
  /** Short text before the input, e.g. a currency code "KES". */
  prefix?: string; suffix?: string;
}
export declare function TextField(props: TextFieldProps): React.ReactElement;

export interface SelectProps extends React.SelectHTMLAttributes<HTMLSelectElement> {
  label?: string; help?: string; error?: string; placeholder?: string;
  options: Array<string | { value: string; label: string }>;
}
export declare function Select(props: SelectProps): React.ReactElement;

export interface CheckboxProps extends React.InputHTMLAttributes<HTMLInputElement> { label: React.ReactNode; help?: string }
export declare function Checkbox(props: CheckboxProps): React.ReactElement;

export interface SwitchProps { checked: boolean; onChange?: (next: boolean) => void; label?: React.ReactNode; disabled?: boolean; id?: string; className?: string }
export declare function Switch(props: SwitchProps): React.ReactElement;

export interface StatusBadgeProps { tone?: Tone; children: React.ReactNode; className?: string }
export declare function StatusBadge(props: StatusBadgeProps): React.ReactElement;

export interface AlertProps { tone?: 'info' | 'success' | 'warning' | 'danger'; title?: React.ReactNode; children?: React.ReactNode; action?: React.ReactNode; className?: string }
export declare function Alert(props: AlertProps): React.ReactElement;

export interface SyncStatusProps { state: 'online' | 'syncing' | 'offline'; /** Sales waiting to upload. */ pending?: number; labels?: { online?: string; syncing?: string; offline?: string }; className?: string }
export declare function SyncStatus(props: SyncStatusProps): React.ReactElement;

export interface MoneyProps { amount: number; currency: string; /** Second currency shown below, e.g. CDF under USD. */ secondary?: MoneyValue; size?: 'md' | 'lg'; tone?: 'success' | 'danger'; locale?: string; className?: string }
export declare function Money(props: MoneyProps): React.ReactElement;

export interface KpiTileProps { label: string; value: React.ReactNode; /** Percent change; sign gives direction. */ delta?: number; period?: string; lowerIsBetter?: boolean; className?: string }
export declare function KpiTile(props: KpiTileProps): React.ReactElement;

export interface DataTableColumn<Row> { key: string; label: string; align?: 'start' | 'end'; numeric?: boolean; render?: (row: Row) => React.ReactNode }
export interface DataTableProps<Row extends { id?: string }> { columns: DataTableColumn<Row>[]; rows: Row[]; caption?: string; emptyText?: string; selectedId?: string; onRowClick?: (row: Row) => void; className?: string }
export declare function DataTable<Row extends { id?: string }>(props: DataTableProps<Row>): React.ReactElement;

export interface CardProps { title?: React.ReactNode; subtitle?: React.ReactNode; actions?: React.ReactNode; children?: React.ReactNode; className?: string }
export declare function Card(props: CardProps): React.ReactElement;

export interface TabsProps { items: Array<{ value: string; label: string; count?: number }>; value: string; onChange?: (value: string) => void; className?: string }
export declare function Tabs(props: TabsProps): React.ReactElement;

export interface DialogProps { open: boolean; title: string; onClose?: () => void; footer?: React.ReactNode; children?: React.ReactNode; size?: 'md' | 'lg'; /** Render without the overlay (previews, embedded sheets). */ inline?: boolean }
export declare function Dialog(props: DialogProps): React.ReactElement | null;

export interface PosTileProps { name: string; price: number; currency: string; stock?: number; lowStock?: number; image?: string; /** Category colour, shown as a small dot. */ color?: string; onSelect?: () => void; className?: string }
export declare function PosTile(props: PosTileProps): React.ReactElement;

export interface SaleTotalProps { currency: string; subtotal: number; tax: number; total: number; discount?: number; secondary?: MoneyValue; onPay?: () => void; labels?: { subtotal?: string; discount?: string; tax?: string; total?: string; pay?: string }; className?: string }
export declare function SaleTotal(props: SaleTotalProps): React.ReactElement;

export interface StageTrackerProps { stages: string[]; current: string; /** Shows the current stage as stopped (rejected, failed condition). */ blocked?: boolean; className?: string }
export declare function StageTracker(props: StageTrackerProps): React.ReactElement;

export interface ApprovalCardProps {
  docType: string; number: string; title: string; /** Omit for documents without a value, such as leave. */ amount?: number; currency?: string; requester: string;
  branch?: string; onBehalfOf?: string; due?: string; overdue?: boolean; escalatesIn?: string;
  onApprove?: () => void; onReject?: () => void; onReturn?: () => void; className?: string;
}
export declare function ApprovalCard(props: ApprovalCardProps): React.ReactElement;

export declare function formatAmount(amount: number, currency: string, locale?: string): string;

declare global {
  interface Window {
    DS: {
      Button: typeof Button; TextField: typeof TextField; Select: typeof Select; Checkbox: typeof Checkbox; Switch: typeof Switch;
      StatusBadge: typeof StatusBadge; Alert: typeof Alert; SyncStatus: typeof SyncStatus;
      Money: typeof Money; KpiTile: typeof KpiTile; DataTable: typeof DataTable;
      Card: typeof Card; Tabs: typeof Tabs; Dialog: typeof Dialog;
      PosTile: typeof PosTile; SaleTotal: typeof SaleTotal;
      StageTracker: typeof StageTracker; ApprovalCard: typeof ApprovalCard;
      formatAmount: typeof formatAmount;
    };
  }
}

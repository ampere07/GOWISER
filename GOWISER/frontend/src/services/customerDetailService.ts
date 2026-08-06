import apiClient from '../config/api';

export interface CustomerDetailData {
  id: number;
  firstName: string;
  middleInitial?: string;
  lastName: string;
  fullName: string;
  emailAddress?: string;
  contactNumberPrimary: string;
  contactNumberSecondary?: string;
  address: string;
  barangay?: string;
  city?: string;
  region?: string;
  addressCoordinates?: string;
  housingStatus?: string;
  referredBy?: string;
  desiredPlan?: string;
  houseFrontPictureUrl?: string;
  proofOfBillingUrl?: string;
  governmentValidIdUrl?: string;
  secondGovernmentValidIdUrl?: string;
  documentAttachmentUrl?: string;
  otherIspBillUrl?: string;
  accountNoCustomer?: string;
  updatedBy?: string;
  groupId?: number;
  groupName?: string;

  billingAccount?: {
    id: number;
    accountNo: string;
    dateInstalled?: string;
    billingDay: number;
    billingStatusId: number;
    billingStatusName?: string;
    accountBalance: number;
    balanceUpdateDate?: string;
    createdAt?: string;
    createdBy?: string;
    updatedAt?: string;
    updatedBy?: string;
    vip_expiration?: string;
    vip_remarks?: string;
    generation_type?: string;
    prepaid_expires_at?: string;
    // Prepaid plan change already paid for but not yet in effect. The dashboard prices a top-up at
    // this plan rather than the one being replaced.
    pending_plan_id?: number | null;
    pending_plan_name?: string | null;
    pending_plan_effective_at?: string | null;
    vat_type?: string;
    vat_enabled?: boolean | null;
    withholding_enabled?: boolean | null;
    withholding_percentage?: number | string | null;
  };

  technicalDetails?: {
    id: number;
    username?: string;
    pppoePassword?: string | null;
    pppoeUsername?: string | null;
    usernameStatus?: string;
    connectionType?: string;
    routerModel?: string;
    routerModemSn?: string;
    ipAddress?: string;
    lcp?: string;
    nap?: string;
    port?: string;
    vlan?: string;
    lcpnap?: string;
    usageTypeId?: number;
    usageType?: string;
    createdAt?: string;
    createdBy?: string;
    updatedAt?: string;
    updatedBy?: string;
  };

  createdAt?: string;
  updatedAt?: string;
  onlineSessionStatus?: string;
  session_group?: string;
  session_ip?: string;
  /** Live RADIUS sessions on this account. Fallback for `sessionStatusFrom()` when
   *  session_status is absent — see utils/onlineStatus.ts. */
  active_sessions?: number;
  /** When the RADIUS sync last wrote this account's online_status row. */
  session_updated_at?: string | null;
  onlineStatusData?: any;
}

interface CustomerDetailApiResponse {
  success: boolean;
  data?: CustomerDetailData;
  message?: string;
}

export const getCustomerDetail = async (accountNo: string): Promise<CustomerDetailData | null> => {
  try {
    const response = await apiClient.get<CustomerDetailApiResponse>(`/customer-detail/${accountNo}`);

    if (response.data?.success && response.data?.data) {
      const data = response.data.data;
      return data;
    }

    return null;
  } catch (error) {
    return null;
  }
};

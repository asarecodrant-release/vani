(function () {
  window.VaniPaymentRenderer = {
    renderPaymentForm(action, context, suggestionsBox, helpers) {
      const {
        addMessage,
        api,
        chatMessagesContainer,
        clearActiveFaqActionState,
        config,
        css,
        customerId,
        faqFeedbackEnabledFor,
        isEnabled,
        loadRazorpayCheckout,
        normalizePhone,
        openMobileOtpWidget,
        openRazorpayOnParent,
        renderFaqActionFeedback,
        sessionId,
        sourceUrl,
        themeAccent,
        themeBackground,
        userId,
        validEmail,
        validPhone
      } = helpers;
    if (!suggestionsBox) return;
    clearActiveFaqActionState();
    const paymentAction = Array.isArray(config.payment_actions)
      ? config.payment_actions.find(item => String(item.id || "") === String(action.action_value || ""))
      : null;
    const isUpiPayment = (paymentAction?.payment_method || action.payment_method || "").toLowerCase() === "upi";
    const collectPayerEmail = !(config.payment_collect_payer_email === false || config.payment_collect_payer_email === 0 || config.payment_collect_payer_email === "0" || config.payment_collect_payer_email === "false");
    const collectPayerPhone = !(config.payment_collect_payer_phone === false || config.payment_collect_payer_phone === 0 || config.payment_collect_payer_phone === "0" || config.payment_collect_payer_phone === "false");
    const verifyPayerEmailOtp = collectPayerEmail && isEnabled(config.payment_verify_payer_email_otp);
    const verifyPayerPhoneOtp = collectPayerPhone && isEnabled(config.payment_verify_payer_phone_otp);
    suggestionsBox.innerHTML = "";
    suggestionsBox.style.display = "grid";
    const panel = document.createElement("form");
    panel.className = "vani-action-panel";
    css(panel, {display: "grid", gap: "8px", padding: "11px", borderRadius: "16px", background: "rgba(255,255,255,.95)", border: "1px solid rgba(199,210,254,.75)", boxShadow: "0 14px 34px rgba(15,23,42,.10)"});
    const title = document.createElement("strong");
    title.textContent = action.label || "Pay now";
    css(title, {fontSize: "13px", color: "#0f172a"});
    const nameInput = document.createElement("input");
    const emailInput = document.createElement("input");
    const phoneInput = document.createElement("input");
    const emailOtpInput = document.createElement("input");
    let emailOtpLeadId = "";
    let emailVerifiedValue = "";
    let phoneVerifiedValue = "";
    const contactFields = [[nameInput, "Your name"]];
    if (collectPayerEmail) contactFields.push([emailInput, "Email address"]);
    if (collectPayerPhone) contactFields.push([phoneInput, "Mobile number"]);
    contactFields.forEach(([field, placeholder]) => {
      field.placeholder = placeholder;
      css(field, {width: "100%", boxSizing: "border-box", border: "1px solid #e2e8f0", borderRadius: "12px", padding: "9px 10px", font: "inherit", fontSize: "12px", outline: "none"});
    });
    emailInput.type = "email";
    phoneInput.type = "tel";
    emailOtpInput.type = "text";
    emailOtpInput.inputMode = "numeric";
    emailOtpInput.maxLength = 6;
    emailOtpInput.placeholder = "Email OTP";
    css(emailOtpInput, {display: "none", width: "100%", boxSizing: "border-box", border: "1px solid #c7d2fe", borderRadius: "12px", padding: "9px 10px", font: "inherit", fontSize: "12px", outline: "none"});
    const submit = document.createElement("button");
    submit.type = "submit";
    submit.textContent = "Continue to payment";
    css(submit, {border: "0", borderRadius: "12px", background: themeBackground(), color: "#fff", cursor: "pointer", fontWeight: "800", padding: "10px 12px"});
    const status = document.createElement("div");
    css(status, {display: "none", padding: "8px 10px", borderRadius: "10px", fontSize: "12px", fontWeight: "700"});
    const setStatus = (text, ok = false) => {
      status.textContent = text;
      status.style.display = "block";
      status.style.background = ok ? "rgba(34,197,94,.12)" : "rgba(239,68,68,.1)";
      status.style.color = ok ? "#166534" : "#991b1b";
    };
    const renderUpiReferenceForm = transactionId => {
      if (!transactionId || panel.querySelector(".vani-upi-reference-form")) return;
      const referenceWrap = document.createElement("div");
      referenceWrap.className = "vani-upi-reference-form";
      css(referenceWrap, {display: "grid", gap: "8px", padding: "10px", borderRadius: "14px", background: "rgba(240,253,244,.9)", border: "1px solid rgba(34,197,94,.22)"});
      const help = document.createElement("small");
      help.textContent = "After payment, enter your UPI transaction ID so the business can verify and confirm on your mobile number.";
      css(help, {color: "#166534", fontWeight: "700", lineHeight: "1.45"});
      const referenceInput = document.createElement("input");
      referenceInput.placeholder = "UPI transaction ID";
      css(referenceInput, {width: "100%", boxSizing: "border-box", border: "1px solid #bbf7d0", borderRadius: "12px", padding: "9px 10px", font: "inherit", fontSize: "12px", outline: "none"});
      const referenceButton = document.createElement("button");
      referenceButton.type = "button";
      referenceButton.textContent = "Submit transaction ID";
      css(referenceButton, {border: "0", borderRadius: "12px", background: "#16a34a", color: "#fff", cursor: "pointer", fontWeight: "800", padding: "10px 12px"});
      referenceButton.onclick = async () => {
        const upiReference = referenceInput.value.trim();
        if (!upiReference) {
          setStatus("Enter the UPI transaction ID after payment.");
          referenceInput.focus();
          return;
        }
        referenceButton.disabled = true;
        referenceButton.textContent = "Saving...";
        const saveResponse = await api("submit_upi_transaction_reference", "POST", {
          customer_id: customerId,
          transaction_id: transactionId,
          upi_reference: upiReference
        });
        referenceButton.disabled = false;
        referenceButton.textContent = "Submit transaction ID";
        if (!saveResponse.success) {
          setStatus(saveResponse.message || "Transaction ID could not be saved.");
          return;
        }
        referenceInput.disabled = true;
        referenceButton.disabled = true;
        referenceButton.textContent = "Submitted";
        setStatus(saveResponse.message || "Transaction ID saved. The business will verify and confirm.", true);
      };
      referenceWrap.appendChild(help);
      referenceWrap.appendChild(referenceInput);
      referenceWrap.appendChild(referenceButton);
      panel.insertBefore(referenceWrap, status);
    };
    const openUpiLink = (link, transactionId = "") => {
      window.location.href = link;
      setStatus("UPI app opened. Payment will remain pending until the business verifies it.", true);
      submit.disabled = false;
      submit.textContent = "Open UPI options again";
      if (config.upi_transaction_id_required !== false && config.upi_transaction_id_required !== 0 && config.upi_transaction_id_required !== "0" && config.upi_transaction_id_required !== "false") {
        renderUpiReferenceForm(transactionId);
      }
      showFaqActionFeedback(action, context, suggestionsBox, 250);
    };
    const upiIntentLink = (upiLink, packageName = "") => {
      const intentPath = upiLink.replace(/^upi:\/\//i, "");
      return `intent://${intentPath}#Intent;scheme=upi;${packageName ? `package=${packageName};` : ""}end`;
    };
    const renderUpiChoices = (upiLink, transactionId = "") => {
      let choices = panel.querySelector(".vani-upi-choice-grid");
      if (choices) choices.remove();
      choices = document.createElement("div");
      choices.className = "vani-upi-choice-grid";
      css(choices, {display: "grid", gridTemplateColumns: "1fr 1fr", gap: "8px"});
      const apps = [
        ["Google Pay", "com.google.android.apps.nbu.paisa.user"],
        ["PhonePe", "com.phonepe.app"],
        ["Paytm", "net.one97.paytm"],
        ["BHIM", "in.org.npci.upiapp"]
      ];
      apps.forEach(([label, packageName]) => {
        const button = document.createElement("button");
        button.type = "button";
        button.textContent = label;
        css(button, {border: "1px solid #e2e8f0", borderRadius: "12px", background: "#fff", color: "#0f172a", cursor: "pointer", fontWeight: "800", padding: "9px 10px", fontSize: "12px"});
        button.onclick = () => openUpiLink(upiIntentLink(upiLink, packageName), transactionId);
        choices.appendChild(button);
      });
      const other = document.createElement("button");
      other.type = "button";
      other.textContent = "Other UPI app";
      css(other, {gridColumn: "1 / -1", border: "0", borderRadius: "12px", background: themeBackground(), color: "#fff", cursor: "pointer", fontWeight: "800", padding: "10px 12px"});
      other.onclick = () => openUpiLink(upiIntentLink(upiLink), transactionId);
      choices.appendChild(other);
      panel.insertBefore(choices, submit);
    };
    const finishSuccessfulPayment = message => {
      setStatus(message, true);
      const messageBox = chatMessagesContainer();
      if (messageBox) addMessage(messageBox, message, "bot");
      if (faqFeedbackEnabledFor(action)) {
        clearActiveFaqActionState();
        suggestionsBox.innerHTML = "";
        suggestionsBox.style.display = "grid";
        window.setTimeout(() => renderFaqActionFeedback(action, context, suggestionsBox), 250);
        return;
      }
      clearActiveFaqActionState();
      let remaining = 5;
      submit.disabled = true;
      submit.textContent = `Closing in ${remaining}s`;
      const timer = window.setInterval(() => {
        remaining -= 1;
        if (remaining > 0) {
          submit.textContent = `Closing in ${remaining}s`;
          return;
        }
        window.clearInterval(timer);
        panel.remove();
        if (!suggestionsBox.children.length) {
          suggestionsBox.style.display = "none";
        }
        const messageBox = chatMessagesContainer();
        if (messageBox) addMessage(messageBox, "How can I help you further?", "bot");
      }, 1000);
    };
    panel.onsubmit = async event => {
      event.preventDefault();
      if (!nameInput.value.trim()) {
        setStatus("Enter your name to continue.");
        nameInput.focus();
        return;
      }
      const payerEmail = collectPayerEmail ? emailInput.value.trim() : "";
      const payerPhone = collectPayerPhone ? normalizePhone(phoneInput.value.trim()) : "";
      if (collectPayerEmail && payerEmail && !validEmail(payerEmail)) {
        setStatus("Enter a valid email address.");
        emailInput.focus();
        return;
      }
      if (verifyPayerEmailOtp) {
        if (!payerEmail || !validEmail(payerEmail)) {
          setStatus("Enter a valid email address to verify.");
          emailInput.focus();
          return;
        }
        if (emailVerifiedValue !== payerEmail) {
          if (!emailOtpLeadId) {
            submit.disabled = true;
            submit.textContent = "Sending email OTP...";
            const emailRes = await api("create_lead_send_email_otp", "POST", {
              customer_id: customerId,
              user_id: userId,
              email: payerEmail,
              source_url: sourceUrl,
              suppress_notification: true
            });
            submit.disabled = false;
            submit.textContent = "Verify email OTP";
            if (!emailRes.success || !emailRes.lead) {
              setStatus(emailRes.email_error || emailRes.message || "Could not send email OTP. Try again.");
              return;
            }
            emailOtpLeadId = emailRes.lead.id || "";
            emailInput.disabled = true;
            emailOtpInput.style.display = "block";
            emailOtpInput.focus();
            setStatus("Email OTP sent. Enter it to continue.", true);
            return;
          }
          const emailOtp = emailOtpInput.value.trim();
          if (!/^[0-9]{6}$/.test(emailOtp)) {
            setStatus("Enter the 6-digit email OTP.");
            emailOtpInput.focus();
            return;
          }
          submit.disabled = true;
          submit.textContent = "Verifying email...";
          const verifyEmailRes = await api("verify_lead_email_otp", "POST", {
            customer_id: customerId,
            lead_id: emailOtpLeadId,
            otp: emailOtp,
            suppress_notification: true,
            notification_event: "payment"
          });
          submit.disabled = false;
          if (!verifyEmailRes.success) {
            submit.textContent = "Verify email OTP";
            setStatus(verifyEmailRes.message || "Email OTP verification failed.");
            return;
          }
          emailVerifiedValue = payerEmail;
          emailOtpInput.disabled = true;
          emailOtpInput.style.display = "none";
          submit.textContent = "Continue to payment";
          setStatus("Email verified.", true);
        }
      }
      if (verifyPayerPhoneOtp) {
        if (!payerPhone || !validPhone(payerPhone)) {
          setStatus("Enter a valid mobile number with country code to verify.");
          phoneInput.focus();
          return;
        }
        if (phoneVerifiedValue !== payerPhone) {
          submit.disabled = true;
          submit.textContent = "Verifying mobile...";
          try {
            const verified = await openMobileOtpWidget(payerPhone);
            const mobileRes = await api("verify_lead_mobile_msg91", "POST", {
              customer_id: customerId,
              user_id: userId,
              phone_number: verified.phone || payerPhone,
              msg91_access_token: verified.msg91_access_token || "",
              source_url: sourceUrl,
              msg91_response: verified.msg91_response || null,
              suppress_notification: true
            });
            if (!mobileRes.success || !mobileRes.lead) {
              submit.disabled = false;
              submit.textContent = "Continue to payment";
              setStatus(mobileRes.message || "Mobile OTP verification failed.");
              return;
            }
            const verifiedPhone = normalizePhone(mobileRes.lead.phone_number || verified.phone || payerPhone);
            phoneInput.value = verifiedPhone;
            phoneVerifiedValue = verifiedPhone;
            setStatus("Mobile number verified.", true);
          } catch (error) {
            submit.disabled = false;
            submit.textContent = "Continue to payment";
            setStatus("Mobile OTP verification was not completed.");
            return;
          }
        }
      }
      submit.disabled = true;
      submit.textContent = "Preparing...";
      const createPayload = {
        customer_id: customerId,
        payment_action_id: action.action_value,
        faq_action_id: action.id || null,
        faq_id: action.faq_id || context.matched_faq_id || null,
        user_id: userId,
        session_id: sessionId,
        source_url: sourceUrl,
        payer_name: nameInput.value.trim(),
        payer_email: collectPayerEmail ? emailInput.value.trim() : "",
        payer_phone: collectPayerPhone ? phoneInput.value.trim() : ""
      };
      const orderResponse = await api("create_customer_payment_order", "POST", createPayload);
      if (!orderResponse.success) {
        submit.disabled = false;
        submit.textContent = "Continue to payment";
        setStatus(orderResponse.message || "Payment order could not be created.");
        return;
      }
      if (orderResponse.payment_method === "upi" || orderResponse.upi_link) {
        if (!orderResponse.upi_link) {
          submit.disabled = false;
          submit.textContent = "Open UPI app";
          setStatus(orderResponse.message || "UPI payment could not be started.");
          return;
        }
        renderUpiChoices(orderResponse.upi_link, orderResponse.transaction?.id || "");
        setStatus("Choose the UPI app you want to use.", true);
        submit.disabled = false;
        submit.textContent = "Refresh UPI options";
        return;
      }
      if (!orderResponse.order?.id) {
        submit.disabled = false;
        submit.textContent = "Continue to payment";
        setStatus(orderResponse.message || "Payment order could not be created.");
        return;
      }
      const paymentAction = orderResponse.payment_action || {};
      const checkoutOptions = {
        key: orderResponse.key_id,
        amount: paymentAction.amount_paise,
        currency: paymentAction.currency || "INR",
        name: orderResponse.business_name || config.bot_name || "Payment",
        description: paymentAction.description || paymentAction.label || action.label || "Payment",
        order_id: orderResponse.order?.id,
        prefill: {name: nameInput.value.trim(), email: collectPayerEmail ? emailInput.value.trim() : "", contact: collectPayerPhone ? phoneInput.value.trim() : ""},
        theme: {color: themeAccent()}
      };
      const verifyRazorpayPayment = async response => {
        const successText = orderResponse.success_message || "Payment received. Thank you.";
        setStatus("Payment completed. Confirming it now...", true);
        const verify = await api("verify_customer_payment", "POST", {
          customer_id: customerId,
          razorpay_order_id: response.razorpay_order_id,
          razorpay_payment_id: response.razorpay_payment_id,
          razorpay_signature: response.razorpay_signature,
          payer_name: nameInput.value.trim(),
          payer_email: collectPayerEmail ? emailInput.value.trim() : "",
          payer_phone: collectPayerPhone ? phoneInput.value.trim() : ""
        });
        if (verify.success) {
          const message = verify.message || successText;
          finishSuccessfulPayment(message);
        } else {
          submit.disabled = false;
          submit.textContent = "Try payment again";
          setStatus(verify.message || "Payment verification failed.");
        }
      };
      if (window.parent !== window) {
        const parentResult = await openRazorpayOnParent(checkoutOptions);
        if (parentResult?.success && parentResult.response) {
          await verifyRazorpayPayment(parentResult.response);
        } else {
          if (parentResult?.error || parentResult?.payment_id) {
            await api("record_customer_payment_failure", "POST", {
              customer_id: customerId,
              razorpay_order_id: orderResponse.order?.id || "",
              razorpay_payment_id: parentResult?.error?.metadata?.payment_id || parentResult?.payment_id || "",
              message: parentResult?.message || "Payment failed or was cancelled.",
              error: parentResult?.error || null,
              payer_name: nameInput.value.trim(),
              payer_email: collectPayerEmail ? emailInput.value.trim() : "",
              payer_phone: collectPayerPhone ? phoneInput.value.trim() : ""
            });
          }
          submit.disabled = false;
          submit.textContent = "Continue to payment";
          setStatus(parentResult?.message || (parentResult?.dismissed ? "Payment window closed before completion." : "Payment checkout could not be opened."));
        }
        return;
      }
      const loaded = await loadRazorpayCheckout();
      if (!loaded || !window.Razorpay) {
        submit.disabled = false;
        submit.textContent = "Continue to payment";
        setStatus("Payment checkout could not be loaded.");
        return;
      }
      try {
        let checkoutSettled = false;
        let checkout = null;
        const closeCheckout = () => {
          try {
            if (checkout && typeof checkout.close === "function") checkout.close();
          } catch (error) {}
        };
        checkout = new Razorpay({
          ...checkoutOptions,
          handler: response => {
            checkoutSettled = true;
            closeCheckout();
            window.setTimeout(() => verifyRazorpayPayment(response), 300);
          },
          modal: {ondismiss: () => {
            if (checkoutSettled) return;
            submit.disabled = false;
            submit.textContent = "Continue to payment";
            setStatus("Payment window closed before completion.");
          }}
        });
        if (typeof checkout.on === "function") {
          checkout.on("payment.failed", async response => {
            checkoutSettled = true;
            const message = response?.error?.description || "Payment failed or was cancelled.";
            closeCheckout();
            window.setTimeout(async () => {
              await api("record_customer_payment_failure", "POST", {
                customer_id: customerId,
                razorpay_order_id: orderResponse.order?.id || "",
                razorpay_payment_id: response?.error?.metadata?.payment_id || "",
                message,
                error: response?.error || null,
                payer_name: nameInput.value.trim(),
                payer_email: collectPayerEmail ? emailInput.value.trim() : "",
                payer_phone: collectPayerPhone ? phoneInput.value.trim() : ""
              });
              submit.disabled = false;
              submit.textContent = "Try payment again";
              setStatus(message);
              const messageBox = chatMessagesContainer();
              if (messageBox) addMessage(messageBox, message, "bot");
            }, 300);
          });
        }
        checkout.open();
      } catch (error) {
        submit.disabled = false;
        submit.textContent = "Continue to payment";
        setStatus(error?.message || "Payment checkout could not be opened.");
      }
    };
    panel.appendChild(title);
    panel.appendChild(nameInput);
    if (collectPayerEmail) panel.appendChild(emailInput);
    if (verifyPayerEmailOtp) panel.appendChild(emailOtpInput);
    if (collectPayerPhone) panel.appendChild(phoneInput);
    panel.appendChild(submit);
    panel.appendChild(status);
    suggestionsBox.appendChild(panel);
      }
  };
})();
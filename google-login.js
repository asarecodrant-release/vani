function handleCredentialResponse(response) {

    fetch("google-auth.php", {
        method: "POST",
        headers: {
            "Content-Type": "application/json"
        },
        body: JSON.stringify({
            credential: response.credential
        })
    })
    .then(res => res.json())
    .then(data => {

        if(data.success){

            window.location.href = "dashboard.php";

        } else {

            alert(data.message || "Google login failed");
        }
    });
}